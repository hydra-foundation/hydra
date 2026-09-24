<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\Testing\ArrayTwoFactorStore;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\TwoFactorStoreContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArrayTwoFactorStore::class)]
#[CoversClass(TwoFactorStoreContractTestCase::class)]
final class ArrayTwoFactorStoreTest extends TwoFactorStoreContractTestCase
{
    private ArrayTwoFactorStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayTwoFactorStore;
    }

    protected function store(): TwoFactorStoreInterface
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

    public function test_replacing_the_hashes_of_an_account_without_a_second_factor_does_nothing(): void
    {
        $this->store->replaceRecoveryHashes($this->user(), ['n1']);

        $this->assertSame([], $this->store->recoveryHashes($this->user()));
        $this->assertNull($this->store->secret($this->user()));
    }
}
