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

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
