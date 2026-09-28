<?php

declare(strict_types=1);

namespace Hydra\Admin\Files;

use Hydra\Filesystem\StoredFile;

/** A file on a disk that nothing was found pointing at. */
final readonly class Orphan
{
    public function __construct(
        /** "private:docs/….pdf": the file's key with its disk. */
        public string $qualified,
        public StoredFile $file,
    ) {}
}
