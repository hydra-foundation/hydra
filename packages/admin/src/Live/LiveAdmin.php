<?php

declare(strict_types=1);

namespace Hydra\Admin\Live;

/**
 * Whether the admin is live: a broadcaster is bound, so writes are published
 * and screens render the element that listens for them. Off, the admin
 * renders exactly as it would without hydrakit/broadcast installed.
 */
final readonly class LiveAdmin
{
    public function __construct(public bool $enabled = false) {}

    /** The topic a module's screens listen on, and its writes are published to. */
    public static function topic(string $slug): string
    {
        return "module.{$slug}";
    }
}
