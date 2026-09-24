<?php

declare(strict_types=1);

namespace Hydra\Log;

use Closure;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Wraps any PSR-3 logger and adds ambient context to every record — a request
 * id, a job id — asked for at the moment each line is written, so a value set
 * after the logger was built still arrives. A key the record sets itself wins.
 */
final class ContextualLogger extends AbstractLogger
{
    /**
     * @param Closure(): array<string, mixed> $context
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Closure $context,
    ) {}

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, [...($this->context)(), ...$context]);
    }
}
