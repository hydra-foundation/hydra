<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\RequestGuard;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\GuardContractTestCase;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;

/** With no bearer token, the request guard is the session guard in every respect. */
#[CoversClass(RequestGuard::class)]
final class RequestGuardContractTest extends GuardContractTestCase
{
    protected function guard(): GuardInterface
    {
        $hasher = new NativeHasher(new AuthConfig(hashCost: 4));

        $provider = new ArrayUserProvider;
        $provider->add($this->username(), new FakeUser(1, $hasher->hash($this->password())));

        $session = new ArraySessionStore;
        $session->start();

        return new RequestGuard(new SessionGuard($session, $provider, $hasher));
    }
}
