<?php

declare(strict_types=1);

namespace Hydra\Core\Contracts;

use Throwable;

/**
 * Where a fault goes besides the log: an error tracker, a pager. Called for
 * failures only — a 5xx, a failed job, a failed scheduled task — after the log
 * line is written, so the log stays the record and this is an addition to it.
 *
 * A caller survives a reporter that throws, but an implementation should not
 * lean on that: the tracker being down is no reason to lose the response.
 */
interface ExceptionReporterInterface
{
    /**
     * @param array<string, scalar|null> $context where it happened: a request id, a job, a task
     */
    public function report(Throwable $e, array $context = []): void;
}
