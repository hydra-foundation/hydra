<?php

declare(strict_types=1);

namespace Hydra\Queue;

use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Queue\Contracts\QueueInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Jobs in a `jobs` table, failures in `failed_jobs`. Times are unix seconds,
 * so neither database converts a zone on the way in or out.
 */
final class DatabaseQueue implements QueueInterface
{
    public const DEFAULT_RESERVE_SECONDS = 900;

    private const FREE = 'available_at <= ? AND (reserved_at IS NULL OR reserved_at <= ?)';

    /**
     * @param string $driver the PDO driver name; mysql and mariadb claim with SKIP LOCKED
     * @param int $reserveFor seconds before a claim whose worker never finished is taken again
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClockInterface $clock,
        private readonly string $driver,
        private readonly int $reserveFor = self::DEFAULT_RESERVE_SECONDS,
    ) {
        if ($reserveFor < 1) {
            throw new InvalidArgumentException("A claim must be held for at least a second, not {$reserveFor}.");
        }
    }

    public function push(string $job, array $payload = [], int $delay = 0): void
    {
        Payload::checkJob($job, $delay);
        $now = $this->now();

        $this->db->execute(
            'INSERT INTO jobs (job, payload, attempts, available_at, created_at) VALUES (?, ?, 0, ?, ?)',
            [$job, Payload::encode($payload), $now + $delay, $now],
        );
    }

    /**
     * Claim up to $limit available jobs, oldest first, counting the attempt
     * now: a job that kills its worker every time still runs out of tries.
     *
     * @return list<ReservedJob>
     */
    public function reserve(int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("Cannot reserve {$limit} jobs.");
        }

        $now = $this->now();
        $free = [$now, $now - $this->reserveFor];
        $token = bin2hex(random_bytes(16));
        // Other databases serialise the claim on the guarded UPDATE below;
        // SKIP LOCKED only spares a second MariaDB worker the wait.
        $skip = in_array($this->driver, ['mysql', 'mariadb'], true) ? ' FOR UPDATE SKIP LOCKED' : '';

        return $this->db->transaction(function () use ($limit, $now, $free, $token, $skip): array {
            $ids = array_column(
                $this->db->select('SELECT id FROM jobs WHERE ' . self::FREE . " ORDER BY id LIMIT {$limit}{$skip}", $free),
                'id',
            );

            if ($ids === []) {
                return [];
            }

            $in = implode(', ', array_fill(0, count($ids), '?'));
            $this->db->execute(
                "UPDATE jobs SET reserved_at = ?, reservation = ?, attempts = attempts + 1 WHERE id IN ({$in}) AND " . self::FREE,
                [$now, $token, ...$ids, ...$free],
            );

            return array_map(
                static fn (array $row): ReservedJob => new ReservedJob(
                    (int) $row['id'],
                    (string) $row['job'],
                    (string) $row['payload'],
                    (int) $row['attempts'],
                    $token,
                ),
                $this->db->select('SELECT id, job, payload, attempts FROM jobs WHERE reservation = ? ORDER BY id', [$token]),
            );
        });
    }

    public function delete(ReservedJob $job): void
    {
        $this->db->execute('DELETE FROM jobs WHERE id = ? AND reservation = ?', [$job->id, $job->reservation]);
    }

    public function release(ReservedJob $job, int $delay): void
    {
        $this->db->execute(
            'UPDATE jobs SET reserved_at = NULL, reservation = NULL, available_at = ? WHERE id = ? AND reservation = ?',
            [$this->now() + $delay, $job->id, $job->reservation],
        );
    }

    public function fail(ReservedJob $job, Throwable $e): void
    {
        $this->db->transaction(function () use ($job, $e): void {
            if ($this->db->execute('DELETE FROM jobs WHERE id = ? AND reservation = ?', [$job->id, $job->reservation]) === 0) {
                return;
            }

            $this->db->execute(
                'INSERT INTO failed_jobs (job, payload, exception, failed_at) VALUES (?, ?, ?, ?)',
                [$job->job, $job->payload, (string) $e, $this->now()],
            );
        });
    }

    /** @return list<FailedJob> newest first */
    public function failed(): array
    {
        return array_map(
            static fn (array $row): FailedJob => new FailedJob(
                (int) $row['id'],
                (string) $row['job'],
                (string) $row['payload'],
                (string) $row['exception'],
                (int) $row['failed_at'],
            ),
            $this->db->select('SELECT id, job, payload, exception, failed_at FROM failed_jobs ORDER BY id DESC'),
        );
    }

    /**
     * Put a failed job back on the queue with its tries restored, as a new
     * job due now. False when no failed job has that id.
     */
    public function retry(int $id): bool
    {
        return $this->db->transaction(function () use ($id): bool {
            $row = $this->db->selectOne('SELECT job, payload FROM failed_jobs WHERE id = ?', [$id]);

            if ($row === null || $this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]) === 0) {
                return false;
            }

            $now = $this->now();
            $this->db->execute(
                'INSERT INTO jobs (job, payload, attempts, available_at, created_at) VALUES (?, ?, 0, ?, ?)',
                [(string) $row['job'], (string) $row['payload'], $now, $now],
            );

            return true;
        });
    }

    /**
     * Take a job off the queue before any worker has it. False when there is
     * no such job or a worker holds it, stale claims included: the next worker
     * to look takes a stale one, so it is as good as running.
     */
    public function cancel(int $id): bool
    {
        return $this->db->execute('DELETE FROM jobs WHERE id = ? AND reserved_at IS NULL', [$id]) > 0;
    }

    /** Delete one failed job for good. False when no failed job has that id. */
    public function forget(int $id): bool
    {
        return $this->db->execute('DELETE FROM failed_jobs WHERE id = ?', [$id]) > 0;
    }

    /** Delete every failed job, returning how many there were. */
    public function flush(): int
    {
        return $this->db->execute('DELETE FROM failed_jobs');
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
