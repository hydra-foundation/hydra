<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Drivers;

use RuntimeException;

/**
 * Where RedisBroadcaster sends: one PUBLISH. It is an interface so the driver's
 * failure handling can be tested without a server, or the extension.
 *
 * @internal
 */
interface Channel
{
    /** @throws RuntimeException when the message could not be handed over */
    public function publish(string $channel, string $message): void;
}
