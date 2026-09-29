<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SignIn;
use Hydra\Auth\Testing\ArraySignInStore;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\SignInStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArraySignInStore::class)]
#[CoversClass(SignInStoreContractTestCase::class)]
#[CoversClass(SignIn::class)]
final class ArraySignInStoreTest extends SignInStoreContractTestCase
{
    private ArraySignInStore $store;

    protected function setUp(): void
    {
        $this->store = new ArraySignInStore;
    }

    protected function store(): SignInStoreInterface
    {
        return $this->store;
    }

    protected function user(): AuthenticatableInterface
    {
        return new FakeUser(1);
    }

    protected function otherUser(): AuthenticatableInterface
    {
        return new FakeUser(2);
    }
}
