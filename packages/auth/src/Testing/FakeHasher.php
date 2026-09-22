<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\HasherInterface;

/**
 * A hasher that costs nothing and counts what it was asked to do.
 *
 * The shipped {@see \Hydra\Auth\NativeHasher} is slow by design, and a suite
 * that creates users pays for it on every one. This keeps the properties the
 * contract case checks, a salted output that is not the plaintext and a verify
 * that matches exactly, and none of the work. It is not a hash: the password
 * is readable in what it returns.
 *
 * The counts are how a test pins work rather than time: a login that must cost
 * the same whether or not the user exists costs the same number of operations.
 */
final class FakeHasher implements HasherInterface
{
    private const PREFIX = 'fake$';

    private int $hashes = 0;

    private int $verifications = 0;

    public function hash(string $plain): string
    {
        $this->hashes++;

        return self::PREFIX . bin2hex(random_bytes(4)) . '$' . $plain;
    }

    public function verify(string $plain, string $hash): bool
    {
        $this->verifications++;

        if (!str_starts_with($hash, self::PREFIX)) {
            return false;
        }

        $parts = explode('$', substr($hash, strlen(self::PREFIX)), 2);

        return count($parts) === 2 && hash_equals($parts[1], $plain);
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }

    public function hashes(): int
    {
        return $this->hashes;
    }

    public function verifications(): int
    {
        return $this->verifications;
    }

    /** Both counts back to zero, so a test can measure only what follows. */
    public function reset(): void
    {
        $this->hashes = 0;
        $this->verifications = 0;
    }
}
