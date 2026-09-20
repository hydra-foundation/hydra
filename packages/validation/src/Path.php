<?php

declare(strict_types=1);

namespace Hydra\Validation;

/**
 * Dot-path access into the input array, and the expansion of a `*` wildcard
 * into the concrete paths the data actually carries.
 */
final class Path
{
    /**
     * The concrete paths a rule key names. A key without a wildcard expands to
     * itself even when the input does not carry it, so a Required on a missing
     * field still fires; a wildcard expands to nothing when its container is
     * absent, because the rule on the container itself is what should complain.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function expand(array $data, string $pattern): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }

        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $next = [];

            foreach ($paths as $prefix) {
                if ($segment !== '*') {
                    $next[] = $prefix === '' ? $segment : "{$prefix}.{$segment}";
                    continue;
                }

                $container = $prefix === '' ? $data : self::get($data, $prefix);

                if (!is_array($container)) {
                    continue;
                }

                foreach (array_keys($container) as $key) {
                    $next[] = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
                }
            }

            $paths = $next;
        }

        return $paths;
    }

    /** @param array<string, mixed> $data */
    public static function get(array $data, string $path): mixed
    {
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /** @param array<string, mixed> $data */
    public static function has(array $data, string $path): bool
    {
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /** @param array<string, mixed> $target */
    public static function set(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $cursor = &$target;

        foreach ($segments as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
    }
}
