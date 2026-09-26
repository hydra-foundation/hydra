<?php

declare(strict_types=1);

namespace Hydra\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Hands every record to each logger it wraps: a file to read later and
 * stderr for the container, say. One that throws is skipped, because a
 * record the others can still keep is worth more than the failure.
 */
final class FanOutLogger extends AbstractLogger
{
    /** @var list<LoggerInterface> */
    private readonly array $loggers;

    public function __construct(LoggerInterface ...$loggers)
    {
        $this->loggers = array_values($loggers);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (Throwable) {
            }
        }
    }
}
