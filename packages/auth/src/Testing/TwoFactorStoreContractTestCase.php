<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see TwoFactorStoreInterface} has to do, the fake included.
 * Extend it with a store over your own tables and two users it can write to.
 *
 * It checks the compare-and-set answers one request at a time. Whether a
 * database implementation holds under two at once is a property of its
 * UPDATE and DELETE, which this cannot race; see the interface.
 */
abstract class TwoFactorStoreContractTestCase extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    abstract protected function store(): TwoFactorStoreInterface;

    /** A user the store can write to, with no second factor yet. */
    abstract protected function user(): AuthenticatableInterface;

    /** A second such user, to show one account's state stays its own. */
    abstract protected function otherUser(): AuthenticatableInterface;

    public function test_an_account_starts_without_a_second_factor(): void
    {
        $store = $this->store();

        $this->assertNull($store->secret($this->user()));
        $this->assertSame([], $store->recoveryHashes($this->user()));
        $this->assertFalse($store->claimStep($this->user(), 1));
    }

    public function test_enable_stores_the_secret_in_the_clear_and_the_hashes(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, ['h1', 'h2']);

        $this->assertSame(self::SECRET, $store->secret($this->user()));
        $this->assertSame(['h1', 'h2'], $store->recoveryHashes($this->user()));
    }

    public function test_a_step_is_claimed_once_and_only_moving_forward(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, []);

        $this->assertTrue($store->claimStep($this->user(), 100));
        $this->assertFalse($store->claimStep($this->user(), 100), 'the same step twice');
        $this->assertFalse($store->claimStep($this->user(), 99), 'an earlier step');
        $this->assertTrue($store->claimStep($this->user(), 101));
    }

    public function test_enabling_again_forgets_the_recorded_step(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, []);
        $store->claimStep($this->user(), 100);

        $store->enable($this->user(), self::SECRET, []);

        $this->assertTrue($store->claimStep($this->user(), 50));
    }

    public function test_a_hash_is_spent_once(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, ['h1', 'h2', 'h3']);

        $this->assertTrue($store->spendRecoveryHash($this->user(), 'h2'));
        $this->assertFalse($store->spendRecoveryHash($this->user(), 'h2'));
        $this->assertFalse($store->spendRecoveryHash($this->user(), 'h9'));
        $this->assertEqualsCanonicalizing(['h1', 'h3'], $store->recoveryHashes($this->user()));
    }

    public function test_replacing_the_hashes_replaces_them_all(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, ['h1', 'h2']);
        $store->spendRecoveryHash($this->user(), 'h1');

        $store->replaceRecoveryHashes($this->user(), ['n1', 'n2', 'n3']);

        $this->assertEqualsCanonicalizing(['n1', 'n2', 'n3'], $store->recoveryHashes($this->user()));
        $this->assertFalse($store->spendRecoveryHash($this->user(), 'h2'));
        $this->assertSame(self::SECRET, $store->secret($this->user()));
    }

    public function test_disable_forgets_everything(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, ['h1']);
        $store->claimStep($this->user(), 100);

        $store->disable($this->user());

        $this->assertNull($store->secret($this->user()));
        $this->assertSame([], $store->recoveryHashes($this->user()));
        $this->assertFalse($store->spendRecoveryHash($this->user(), 'h1'));
        $this->assertFalse($store->claimStep($this->user(), 101));
    }

    public function test_one_account_is_not_another(): void
    {
        $store = $this->store();
        $store->enable($this->user(), self::SECRET, ['h1']);
        $store->enable($this->otherUser(), 'MFRGGZDFMZTWQ2LK', ['o1']);
        $store->claimStep($this->user(), 100);

        $this->assertSame('MFRGGZDFMZTWQ2LK', $store->secret($this->otherUser()));
        $this->assertTrue($store->claimStep($this->otherUser(), 100));
        $this->assertFalse($store->spendRecoveryHash($this->otherUser(), 'h1'));

        $store->disable($this->otherUser());

        $this->assertSame(self::SECRET, $store->secret($this->user()));
    }
}
