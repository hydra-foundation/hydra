<?php

declare(strict_types=1);

namespace Hydra\Queue;

use Hydra\Queue\Contracts\JobInterface;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * What every queue checks before it accepts a push, so the fake refuses
 * exactly what the database would.
 *
 * @internal
 */
final class Payload
{
    private const FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function checkJob(string $job, int $delay): void
    {
        if (!is_a($job, JobInterface::class, true)) {
            throw new InvalidArgumentException("{$job} does not implement " . JobInterface::class . ' and cannot be queued.');
        }

        if ($delay < 0) {
            throw new InvalidArgumentException("A job cannot be delayed by {$delay} seconds; the delay is from now.");
        }
    }

    /** @param array<array-key, mixed> $payload */
    public static function encode(array $payload): string
    {
        self::check($payload, '');

        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | self::FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("The payload cannot be stored as JSON: {$e->getMessage()}.", previous: $e);
        }
    }

    /** @return array<array-key, mixed> */
    public static function decode(string $json): array
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload)
            ? $payload
            : throw new UnexpectedValueException('A queued payload decoded to ' . get_debug_type($payload) . ' rather than an array.');
    }

    /** @param array<array-key, mixed> $values */
    private static function check(array $values, string $path): void
    {
        foreach ($values as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_array($value)) {
                self::check($value, $at);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new InvalidArgumentException(
                    "The payload's \"{$at}\" is a " . get_debug_type($value) . '. A payload holds scalars, null and arrays only; queue the id and load the object in the job.',
                );
            }
        }
    }
}
