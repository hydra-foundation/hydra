<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A local disk the web server serves directly, from a symlink in the document
 * root (see the storage:link command). Safe to hand to nginx as-is because
 * every name on it was chosen by {@see Key}: nothing here ends in .php, and
 * every extension matches what the bytes are.
 */
final class LocalPublicStorage extends LocalStorage implements PublicStorageInterface
{
    private readonly string $baseUrl;

    /** @param string $baseUrl "/storage", or an absolute URL for a separate host */
    public function __construct(string $root, string $baseUrl, StreamFactoryInterface $streams)
    {
        parent::__construct($root, $streams);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function url(string $key): string
    {
        return $this->baseUrl . '/' . Key::valid($key);
    }
}
