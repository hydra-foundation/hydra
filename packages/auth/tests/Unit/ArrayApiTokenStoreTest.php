<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Testing\ApiTokenStoreContractTestCase;
use Hydra\Auth\Testing\ArrayApiTokenStore;
use Hydra\Auth\Testing\FakeUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArrayApiTokenStore::class)]
#[CoversClass(ApiTokenStoreContractTestCase::class)]
#[CoversClass(ApiToken::class)]
final class ArrayApiTokenStoreTest extends ApiTokenStoreContractTestCase
{
    private ArrayApiTokenStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayApiTokenStore;
    }

    protected function store(): ApiTokenStoreInterface
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
