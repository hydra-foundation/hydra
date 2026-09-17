<?php

declare(strict_types=1);

namespace Hydra\Log\Testing;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A PSR-3 logger that keeps its records in memory instead of writing them.
 *
 * It answers two needs at once, which is why there is one class rather than a
 * quiet logger and a capturing one. A test driving real requests wants the
 * pipeline's log line kept out of PHPUnit's own output — and
 * `beStrictAboutOutputDuringTests` cannot catch that line, because a logger
 * holding a stream never passes through output buffering at all. A test about
 * the event chain wants to read the records back and say which ones fired.
 *
 * @phpstan-type Record array{level: mixed, message: string, context: array<string, mixed>}
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<Record> */
    private array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<Record> */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }

    public function has(string $message): bool
    {
        return in_array($message, $this->messages(), true);
    }

    /**
     * The first record carrying this message, or a failure naming what was
     * logged instead — a missing record is the common assertion here, and
     * "no such record" alone sends you back to add a dump.
     *
     * @return Record
     */
    public function firstWith(string $message): array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        throw new RuntimeException(sprintf(
            "No '%s' record was logged. Logged: %s",
            $message,
            $this->messages() === [] ? '(nothing)' : implode(', ', $this->messages()),
        ));
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
