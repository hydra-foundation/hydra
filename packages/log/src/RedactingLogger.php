<?php

declare(strict_types=1);

namespace Hydra\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Wraps any PSR-3 logger and blanks context values whose key names a secret,
 * at any depth, before the inner logger sees them.
 *
 * A key matches when a denylisted fragment appears anywhere in it once case,
 * dashes and underscores are ignored — so `password` also catches
 * `current_password` and `Password-Confirmation`. Over-redacting a harmless
 * field is the cheap failure; the other one is a credential in a log file.
 */
final class RedactingLogger extends AbstractLogger
{
    public const REDACTED = '[redacted]';

    public const FIELDS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'apikey',
        'csrf',
    ];

    /** @var list<string> */
    private readonly array $fragments;

    /**
     * @param list<string> $fields denylisted fragments; extend with [...RedactingLogger::FIELDS, 'iban']
     */
    public function __construct(
        private readonly LoggerInterface $inner,
        array $fields = self::FIELDS,
    ) {
        $this->fragments = array_values(array_filter(array_map(self::normalize(...), $fields)));
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $this->redact($context));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->denied($key)) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    private function denied(string $key): bool
    {
        $key = self::normalize($key);

        foreach ($this->fragments as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $name): string
    {
        return str_replace(['-', '_'], '', strtolower($name));
    }
}
