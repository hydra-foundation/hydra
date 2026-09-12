<?php

declare(strict_types=1);

namespace Hydra\Core;

/**
 * Reads .env once, at construction, and exposes typed accessors.
 */
final class Environment
{
    /** @var array<string, string> values parsed from the .env file */
    private array $data = [];

    public function __construct(private readonly string $basePath)
    {
        $this->load();
    }

    private function load(): void
    {
        $path = $this->basePath . '/.env';

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);

            // The real process environment beats the .env file: skip the
            // file's value entirely so it neither shadows nor exports over a
            // variable the process already has.
            if ($this->fromProcess($key) !== null) {
                continue;
            }

            $value = $this->parseValue(trim($value));

            $this->data[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    /**
     * The value from the real process environment ($_ENV, $_SERVER, getenv),
     * or null when not set there. Only string values count, since $_SERVER
     * also holds non-environment entries such as the `argv` array.
     */
    private function fromProcess(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }

    /** Strips surrounding quotes and any trailing `# comment`. */
    private function parseValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && str_ends_with($value, $first)) {
                return substr($value, 1, -1);
            }
        }

        $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;

        // `KEY= # comment` leaves a bare "#..." with no leading whitespace
        // after the '=' trim; that is still a comment, not a value.
        if (str_starts_with($value, '#')) {
            return '';
        }

        return rtrim($value);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Same precedence load() applies, and it also covers variables the
        // process gained after construction.
        return $this->fromProcess($key) ?? $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->fromProcess($key) !== null || isset($this->data[$key]);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    /** Throws rather than defaulting, for settings with no safe fallback. */
    public function required(string $key): string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException(
                "Required environment variable \"{$key}\" is not set."
            );
        }

        return (string) $value;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        // A present-but-non-integer value is a config error, not a 0. Failing
        // here beats silently coercing "abc" (or "") to 0 deep in the app.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                "Environment value for \"{$key}\" must be an integer, got \"{$value}\"."
            );
        }

        return (int) $value;
    }

    /**
     * A comma-separated value as a list, with blank entries dropped. A missing
     * or empty key is an empty list: "not configured" and "configured to
     * nothing" are the same answer for every setting shaped like this.
     *
     * @param list<string> $default
     * @return list<string>
     */
    public function list(string $key, array $default = []): array
    {
        $value = $this->get($key);

        if ($value === null || trim((string) $value) === '') {
            return array_values($default);
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', (string) $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Accepted forms, case-insensitive: `true`/`false`, `1`/`0`, `yes`/`no`,
     * `on`/`off`. A missing key returns $default; any other present value
     * (including an empty string) throws
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new \InvalidArgumentException(
                "Environment value for \"{$key}\" must be a boolean"
                . " (true/false, 1/0, yes/no, on/off), got \"{$value}\"."
            ),
        };
    }
}
