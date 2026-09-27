<?php

declare(strict_types=1);

namespace Hydra\Filesystem;

use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Contracts\StorageInterface;
use Hydra\Filesystem\Exceptions\InvalidKey;

/**
 * The application's two disks, and the one spelling for "this file, on that
 * disk": a qualified key, "public:blog/ab12….png". A column that stores one
 * says how the file is served without the code reading it having to know.
 */
final class Disks
{
    public const PRIVATE = 'private';
    public const PUBLIC = 'public';

    public function __construct(
        private readonly StorageInterface $private,
        private readonly PublicStorageInterface $public,
    ) {}

    public function private(): StorageInterface
    {
        return $this->private;
    }

    public function public(): PublicStorageInterface
    {
        return $this->public;
    }

    /** @throws InvalidKey for a disk that does not exist */
    public function get(string $name): StorageInterface
    {
        return match ($name) {
            self::PRIVATE => $this->private,
            self::PUBLIC => $this->public,
            default => throw InvalidKey::unknownDisk($name),
        };
    }

    /** @throws InvalidKey */
    public function qualify(string $disk, string $key): string
    {
        $this->get($disk);

        return $disk . ':' . Key::valid($key);
    }

    /**
     * The disk a qualified key is on, and the key within it.
     *
     * @return array{0: StorageInterface, 1: string}
     * @throws InvalidKey
     */
    public function locate(string $qualified): array
    {
        [$disk, $key] = $this->split($qualified);

        return [$this->get($disk), Key::valid($key)];
    }

    /** @throws InvalidKey */
    public function isPublic(string $qualified): bool
    {
        return $this->split($qualified)[0] === self::PUBLIC;
    }

    /** @return array{0: string, 1: string} */
    private function split(string $qualified): array
    {
        $parts = explode(':', $qualified, 2);

        if (count($parts) !== 2) {
            throw InvalidKey::of($qualified);
        }

        return [$parts[0], $parts[1]];
    }
}
