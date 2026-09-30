<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

/**
 * An error reply, told apart from a simple string.
 *
 * @internal
 */
final readonly class RespError
{
    public function __construct(public string $message) {}
}
