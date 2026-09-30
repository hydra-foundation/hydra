<?php

declare(strict_types=1);

namespace Hydra\Broadcast;

use InvalidArgumentException;

/**
 * The naming rules for topics and event names.
 *
 * A topic is dot-separated segments of [a-z0-9_-], such as `module.users` or
 * `user.7`, at most 128 characters. It becomes part of a Redis channel name
 * and is what a listen token grants, so no wildcard, no colon and no space.
 *
 * An event name is [a-z0-9_.-], at most 64 characters. It is written into an
 * SSE `event:` line and matched by htmx's `sse:<name>` trigger, so a newline
 * would start a second field and a colon would split the trigger.
 */
final class Topic
{
    public const MAX_LENGTH = 128;
    public const MAX_EVENT_LENGTH = 64;

    private const TOPIC = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)*$/D';
    private const EVENT = '/^[a-z0-9_.-]+$/D';

    private function __construct() {}

    public static function isValid(string $topic): bool
    {
        return strlen($topic) <= self::MAX_LENGTH && preg_match(self::TOPIC, $topic) === 1;
    }

    /** @throws InvalidArgumentException naming the topic */
    public static function assertValid(string $topic): void
    {
        if (!self::isValid($topic)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid broadcast topic: use dot-separated segments of a-z, 0-9, _ and -, at most %d characters.',
                $topic,
                self::MAX_LENGTH,
            ));
        }
    }

    public static function isValidEvent(string $event): bool
    {
        return strlen($event) <= self::MAX_EVENT_LENGTH && preg_match(self::EVENT, $event) === 1;
    }

    /** @throws InvalidArgumentException naming the event */
    public static function assertValidEvent(string $event): void
    {
        if (!self::isValidEvent($event)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid broadcast event name: use a-z, 0-9, _, . and -, at most %d characters.',
                $event,
                self::MAX_EVENT_LENGTH,
            ));
        }
    }
}
