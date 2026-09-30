<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * Broadcast settings, validated at construction so a bad driver fails at boot
 * rather than at the first publish.
 *
 * The redis driver has no connection settings of its own: it uses REDIS_*,
 * the same server the cache talks to, and the channel prefix follows
 * REDIS_PREFIX so two apps sharing a server do not hear each other.
 */
final readonly class BroadcastConfig
{
    public const REDIS = 'redis';
    public const LOG = 'log';
    public const NULL = 'null';

    private const DRIVERS = [self::REDIS, self::LOG, self::NULL];

    public function __construct(
        public string $driver = self::NULL,
        public string $channelPrefix = 'broadcast.',
    ) {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Broadcast driver must be one of %s; got "%s".',
                implode(', ', self::DRIVERS),
                $driver,
            ));
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            driver: $env->string('BROADCAST_DRIVER', self::NULL),
            channelPrefix: $env->string('REDIS_PREFIX', '') . 'broadcast.',
        );
    }
}
