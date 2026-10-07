<?php

declare(strict_types=1);

namespace Hydra\View;

/** One size of a picture: where it is served, and how big it is. */
final readonly class Variant
{
    public function __construct(
        public string $url,
        public int $width,
        public int $height,
    ) {}
}
