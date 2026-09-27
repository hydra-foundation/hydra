<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use Hydra\Core\Environment;

/**
 * Where the two disks live. Paths come from the application, because only it
 * knows its own layout; the URL may come from FILESYSTEM_PUBLIC_URL, for a
 * public disk served from another host.
 */
final readonly class FilesystemConfig
{
    public function __construct(
        public string $privateRoot,
        public string $publicRoot,
        public string $publicLink,
        public string $publicUrl = '/storage',
    ) {}

    /**
     * @param string $storagePath the application's storage directory
     * @param string $publicPath  the web server's document root
     */
    public static function fromEnvironment(Environment $env, string $storagePath, string $publicPath): self
    {
        $storagePath = rtrim($storagePath, '/');

        return new self(
            privateRoot: $storagePath . '/uploads',
            publicRoot: $storagePath . '/public',
            publicLink: rtrim($publicPath, '/') . '/storage',
            publicUrl: $env->string('FILESYSTEM_PUBLIC_URL', '/storage'),
        );
    }
}
