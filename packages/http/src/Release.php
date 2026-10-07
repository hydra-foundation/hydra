<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * The deployed version of the application, mixed into every ETag so a deploy
 * that changes a template or a stylesheet makes every page new again without
 * each controller having to remember it.
 *
 * Not conditional in development: a template edited there doesn't change the
 * release, and a stale page from a 304 is worse than a full one.
 */
final readonly class Release
{
    public function __construct(
        public string $id,
        public bool $conditional = true,
    ) {}
}
