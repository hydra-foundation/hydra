<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Contracts;

use Hydra\Filesystem\Exceptions\InvalidKey;

/**
 * A disk whose files anyone may fetch. Only such a disk has url(), so code that
 * wants a link to a file has to be holding one: asking a private disk for a URL
 * does not compile past static analysis, rather than leaking at runtime.
 */
interface PublicStorageInterface extends StorageInterface
{
    /**
     * Where a browser fetches the file. Built from the key alone, without
     * checking the file is there: a page of links should not stat a disk.
     *
     * @throws InvalidKey
     */
    public function url(string $key): string;
}
