<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Testing\UserProviderContractTestCase;
use Hydra\Auth\Tests\Support\ArrayUserProvider;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The contract case against the array provider. Nothing of auth's own is under
 * test here — auth ships no user provider — so this covers nothing and exists
 * to keep the published case honest: a contract with no subclass in its own
 * repository can be broken without anything going red.
 */
#[CoversNothing]
final class ArrayUserProviderTest extends UserProviderContractTestCase
{
    protected function provider(): UserProviderInterface
    {
        $provider = new ArrayUserProvider;
        // One integer key and one string: the interface allows both, and a
        // provider is the thing that decides which an application uses.
        $provider->add('admin', $this->user(7, 'hash-for-admin'));
        $provider->add('clerk', $this->user('u-8', 'hash-for-clerk'));

        return $provider;
    }

    protected function knownUsername(): string
    {
        return 'admin';
    }

    private function user(int|string $id, string $hash): AuthenticatableInterface
    {
        return new class ($id, $hash) implements AuthenticatableInterface {
            public function __construct(private readonly int|string $id, private readonly string $hash) {}

            public function getAuthIdentifier(): int|string
            {
                return $this->id;
            }

            public function getAuthPassword(): string
            {
                return $this->hash;
            }
        };
    }
}
