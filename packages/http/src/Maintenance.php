<?php

declare(strict_types=1);

namespace Hydra\Http;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Whether the application is down for maintenance, held in a file so `down`
 * from a shell and every php-fpm worker agree without a shared store — Redis
 * may be the very thing the maintenance is for.
 *
 * One server's disk: an application behind several needs `down` run on each.
 */
final class Maintenance
{
    public const DEFAULT_MESSAGE = 'Down for maintenance. Back shortly.';

    public function __construct(private readonly string $path) {}

    public function down(string $message = self::DEFAULT_MESSAGE, ?int $retryAfter = null): void
    {
        if ($retryAfter !== null && $retryAfter < 1) {
            throw new InvalidArgumentException('A retry needs to be at least one second.');
        }

        $dir = dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir} for the maintenance flag.");
        }

        $json = json_encode(
            ['message' => $message, 'retry' => $retryAfter, 'since' => time()],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // Written aside and renamed, so a worker never reads half a file.
        $tmp = $this->path . '.' . bin2hex(random_bytes(4));

        if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $this->path)) {
            @unlink($tmp);

            throw new RuntimeException("Could not write the maintenance flag at {$this->path}.");
        }
    }

    /** False when it was already up. */
    public function up(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        if (!@unlink($this->path)) {
            throw new RuntimeException("Could not remove the maintenance flag at {$this->path}.");
        }

        return true;
    }

    /**
     * Null when up. A flag that will not parse still means down: someone put it
     * there, and serving through it is the worse guess.
     *
     * @return array{message: string, retry: int|null, since: int|null}|null
     */
    public function current(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $down = ['message' => self::DEFAULT_MESSAGE, 'retry' => null, 'since' => null];

        try {
            $read = json_decode((string) @file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $down;
        }

        if (!is_array($read)) {
            return $down;
        }

        return [
            'message' => is_string($read['message'] ?? null) && $read['message'] !== '' ? $read['message'] : $down['message'],
            'retry' => is_int($read['retry'] ?? null) && $read['retry'] > 0 ? $read['retry'] : null,
            'since' => is_int($read['since'] ?? null) ? $read['since'] : null,
        ];
    }
}
