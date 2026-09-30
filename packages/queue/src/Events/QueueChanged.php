<?php

declare(strict_types=1);

namespace Hydra\Queue\Events;

/**
 * Rows moved in the queue's tables. Which rows is not said: whatever shows the
 * tables asks again. Dispatched once per push, once per worker batch that did
 * anything, and once per retry, cancel, forget or flush that changed a row.
 */
final readonly class QueueChanged
{
    /** The jobs table: queued, held, done or given back. */
    public const JOBS = 'jobs';

    /** The failed_jobs table. */
    public const FAILED = 'failed';

    /** @param non-empty-list<self::JOBS|self::FAILED> $tables */
    public function __construct(public array $tables) {}
}
