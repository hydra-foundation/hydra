<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Disks;
use Hydra\Filesystem\Exceptions\InvalidKey;
use Hydra\Filesystem\Key;

/**
 * Where a browser fetches a stored file from, by the disk its key names: the
 * web server's URL for a public file, the admin's {@see FileController} for a
 * private one. A key that is neither has no URL, and a template shows it as
 * text rather than as an image that will never load.
 */
final readonly class FileUrls
{
    private const IMAGES = ['jpg', 'png', 'gif', 'webp', 'avif'];

    public function __construct(
        private string $prefix,
        private ?Disks $disks = null,
    ) {}

    /**
     * $name is what the file was uploaded as, when it was kept. A private file
     * is downloaded under it; a public one is the web server's to serve, at a
     * URL made of its key alone.
     */
    public function url(string $qualified, ?string $name = null): ?string
    {
        try {
            if ($this->disks !== null) {
                [$disk, $key] = $this->disks->locate($qualified);

                return $disk instanceof PublicStorageInterface ? $disk->url($key) : $this->privateUrl($qualified, $name);
            }

            // With no disks bound, a private key still names the admin's
            // route, and nothing else can be resolved at all.
            if (str_starts_with($qualified, Disks::PRIVATE . ':')) {
                Key::valid(substr($qualified, strlen(Disks::PRIVATE) + 1));

                return $this->privateUrl($qualified, $name);
            }
        } catch (InvalidKey) {
        }

        return null;
    }

    /**
     * Whether the key is one a browser can show as an image. Read from the
     * extension, which is trustworthy here in a way it never is on an upload:
     * the disk chose it from the bytes.
     */
    public function isImage(string $qualified): bool
    {
        return in_array(strtolower(pathinfo($qualified, PATHINFO_EXTENSION)), self::IMAGES, true);
    }

    private function privateUrl(string $qualified, ?string $name): string
    {
        $url = rtrim($this->prefix, '/') . '/files?key=' . rawurlencode($qualified);

        return $name === null || $name === '' ? $url : $url . '&name=' . rawurlencode($name);
    }
}
