<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

/** Where the admin learns which Hydra releases exist. */
interface ReleaseFeedInterface
{
    /** @return array<mixed>|null the decoded feed, or null when it could not be read */
    public function fetch(): ?array;
}
