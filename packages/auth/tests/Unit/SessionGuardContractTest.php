<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\GuardContractTestCase;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SessionGuard::class)]
final class SessionGuardContractTest extends GuardContractTestCase
{
    protected function guard(): GuardInterface
    {
        $hasher = new NativeHasher(new AuthConfig(hashCost: 4));
        $hash = $hasher->hash($this->password());

        $provider = new ArrayUserProvider;
        $provider->add($this->username(), new FakeUser(1, $hash));

        $session = new ArraySessionStore;
        $session->start();

        return new SessionGuard($session, $provider, $hasher);
    }
}
