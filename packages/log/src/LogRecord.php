<?php

declare(strict_types=1);

namespace Hydra\Log;

use DateTimeImmutable;

/** One record read back from a StreamLogger file. */
final readonly class LogRecord
{
    /**
     * @param int $offset the byte its first line starts at, which names it until the file is rotated
     * @param string $level a PSR-3 level, lower-case
     * @param string|null $detail the exception and its trace, or any lines after the first
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $offset,
        public DateTimeImmutable $time,
        public string $level,
        public string $message,
        public ?string $detail,
        public array $context,
        public ?string $requestId,
    ) {}
}
