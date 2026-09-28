<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

/** One place a stored file is pointed at from. */
final readonly class Reference
{
    public function __construct(
        /** Qualified: "private:avatars/….png". */
        public string $key,
        /** The module's slug, or whatever an application's holder calls itself. */
        public string $holder,
        /** The row's id, when the holder has rows with ids. */
        public ?string $row = null,
        public ?string $column = null,
        /** The original name kept beside the key, if there is one. */
        public ?string $name = null,
    ) {}
}
