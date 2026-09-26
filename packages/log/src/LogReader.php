<?php

declare(strict_types=1);

namespace Hydra\Log;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Reads a StreamLogger file back, from the end. A record is a line starting
 * with its stamp and level, and every line up to the next such line.
 */
final class LogReader
{
    private const START = '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2})\] '
        . '(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG): (.*)$/s';

    /** How StreamLogger renders an exception: class, message, and where it was thrown. */
    private const THROWN = '/^(.*?) ((?:[A-Za-z_]\w*\\\\)*[A-Za-z_]\w*(?:Exception|Error): .* in \S+:\d+)$/s';

    private const RECORD_LIMIT = 1_048_576;

    public function __construct(private readonly string $path) {}

    /**
     * The records in the last $maxBytes of the file, newest first. A record the
     * window cuts into is left out rather than read from its middle.
     *
     * @return list<LogRecord>
     */
    public function latest(int $maxBytes): array
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException("Cannot read the last {$maxBytes} bytes of a log.");
        }

        $size = $this->size();

        if ($size === 0) {
            return [];
        }

        $start = max(0, $size - $maxBytes);
        // One byte early, to tell whether the window opens on a line or inside one.
        $chunk = $this->read($start === 0 ? 0 : $start - 1, $size - $start + ($start === 0 ? 0 : 1));

        if ($start > 0) {
            $newline = strpos($chunk, "\n");

            if ($newline === false) {
                return [];
            }

            $start += $newline;
            $chunk = substr($chunk, $newline + 1);
        }

        return array_reverse($this->parse($chunk, $start));
    }

    /** The record whose first line starts at $offset, or null when none does. */
    public function at(int $offset): ?LogRecord
    {
        $size = $this->size();

        if ($offset < 0 || $offset >= $size) {
            return null;
        }

        if ($offset > 0 && $this->read($offset - 1, 1) !== "\n") {
            return null;
        }

        $records = $this->parse($this->read($offset, min($size - $offset, self::RECORD_LIMIT)), $offset);

        return $records !== [] && $records[0]->offset === $offset ? $records[0] : null;
    }

    /** @return list<LogRecord> oldest first */
    private function parse(string $chunk, int $base): array
    {
        $records = [];
        $current = null;
        $position = $base;

        foreach (explode("\n", $chunk) as $line) {
            $length = strlen($line) + 1;

            if (preg_match(self::START, $line) === 1) {
                if ($current !== null) {
                    $records[] = $this->record(...$current);
                }

                $current = [$position, [$line]];
            } elseif ($current !== null && $line !== '') {
                $current[1][] = $line;
            }

            $position += $length;
        }

        if ($current !== null) {
            $records[] = $this->record(...$current);
        }

        return $records;
    }

    /** @param list<string> $lines */
    private function record(int $offset, array $lines): LogRecord
    {
        preg_match(self::START, $lines[0], $start);
        $lines[0] = $start[3];
        $last = array_key_last($lines);
        [$lines[$last], $context] = $this->context($lines[$last]);

        $message = array_shift($lines);
        $detail = $lines === [] ? null : implode("\n", $lines);

        if ($detail !== null && preg_match(self::THROWN, $message, $thrown) === 1) {
            [$message, $detail] = [$thrown[1], $thrown[2] . "\n" . $detail];
        }

        $requestId = $context['request_id'] ?? null;

        return new LogRecord(
            offset: $offset,
            time: new DateTimeImmutable($start[1]),
            level: strtolower($start[2]),
            message: $message,
            detail: $detail,
            context: $context,
            requestId: is_string($requestId) ? $requestId : null,
        );
    }

    /**
     * Split the trailing JSON object off a line. The longest trailing " {...}"
     * that decodes is the one StreamLogger appended; braces anywhere else are
     * the message's own.
     *
     * @return array{string, array<string, mixed>}
     */
    private function context(string $line): array
    {
        if (!str_ends_with($line, '}')) {
            return [$line, []];
        }

        $at = 0;

        while (($at = strpos($line, ' {', $at)) !== false) {
            $decoded = json_decode(substr($line, $at + 1), true);

            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return [substr($line, 0, $at), $decoded];
            }

            $at++;
        }

        return [$line, []];
    }

    private function size(): int
    {
        clearstatcache(true, $this->path);

        return is_file($this->path) && is_readable($this->path) ? (int) filesize($this->path) : 0;
    }

    private function read(int $from, int $length): string
    {
        $read = $length > 0 ? @file_get_contents($this->path, false, null, $from, $length) : '';

        return is_string($read) ? $read : '';
    }
}
