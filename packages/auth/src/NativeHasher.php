<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\HasherInterface;

/**
 * The production {@see HasherInterface}: PHP's native password hashing.
 */
final class NativeHasher implements HasherInterface
{
    public function __construct(private readonly AuthConfig $config) {}

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT, $this->options());
    }

    public function verify(string $plain, string $hash): bool
    {
        // password_verify is happy to return false for a malformed hash, but an
        // empty stored hash is the "user has no password" case we want to reject
        // explicitly and without touching the verifier at all.
        if ($hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, $this->options());
    }

    /** @return array{cost: int} The work factor for the current policy. */
    private function options(): array
    {
        return ['cost' => $this->config->hashCost];
    }
}
