<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeImmutable;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Scheduler\Contracts\RunLogInterface;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Runs in a `scheduled_runs` table, one row each. Times are unix seconds, as
 * in the queue's tables, so neither database converts a zone.
 */
final class DatabaseRunLog implements RunLogInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClockInterface $clock,
    ) {}

    public function record(TaskRun $run): void
    {
        if ($run->startedAt === null) {
            throw new InvalidArgumentException("The run of {$run->class} cannot be recorded without when it started.");
        }

        $this->db->execute(
            'INSERT INTO scheduled_runs (task, outcome, items, held_minutes, error, started_at, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$run->class, $run->outcome->value, $run->items, $run->heldMinutes, $run->error, $run->startedAt->getTimestamp(), $run->durationMs],
        );
    }

    /**
     * The newest run of each task that has one, newest first.
     *
     * @return array<class-string, TaskRun>
     */
    public function latest(): array
    {
        $rows = $this->db->select(
            'SELECT r.task, r.outcome, r.items, r.held_minutes, r.error, r.started_at, r.duration_ms FROM scheduled_runs r'
            . ' JOIN (SELECT MAX(id) AS id FROM scheduled_runs GROUP BY task) l ON r.id = l.id ORDER BY r.id DESC',
        );
        $latest = [];

        foreach ($rows as $row) {
            /** @var class-string $task */
            $task = (string) $row['task'];
            $latest[$task] = new TaskRun(
                $task,
                Outcome::from((string) $row['outcome']),
                self::int($row['items']),
                self::int($row['held_minutes']),
                new DateTimeImmutable('@' . (int) $row['started_at']),
                self::int($row['duration_ms']),
                $row['error'] === null ? null : (string) $row['error'],
            );
        }

        return $latest;
    }

    /** Delete the runs that started more than $days ago, and say how many went. */
    public function prune(int $days): int
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Runs are kept for at least one day, not {$days}.");
        }

        return $this->db->execute(
            'DELETE FROM scheduled_runs WHERE started_at < ?',
            [$this->clock->now()->getTimestamp() - $days * 86400],
        );
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
