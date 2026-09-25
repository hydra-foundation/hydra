<?php

declare(strict_types=1);

namespace Hydra\Admin\Updates;

/**
 * Where the installed Hydra stands against the newest release. A patch is
 * inside the installed constraint and `hydra:upgrade` reaches it; a new series
 * is not, since ^0.9 never resolves to 0.10, so it comes with the notes instead.
 */
final readonly class Update
{
    public function __construct(
        public Standing $standing,
        public string $installed,
        public ?string $newest = null,
        public bool $security = false,
        public ?string $notes = null,
    ) {}

    public function available(): bool
    {
        return $this->standing === Standing::Patch || $this->standing === Standing::Series;
    }
}
