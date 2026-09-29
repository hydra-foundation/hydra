<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use DateTimeImmutable;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SignIn;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see SignInStoreInterface} has to do, the fake included. Extend
 * it with a store over your own tables and two users it can write to.
 */
abstract class SignInStoreContractTestCase extends TestCase
{
    abstract protected function store(): SignInStoreInterface;

    /** A user the store can write to, with no sign-ins yet. */
    abstract protected function user(): AuthenticatableInterface;

    /** A second such user, to show one account's sign-ins stay its own. */
    abstract protected function otherUser(): AuthenticatableInterface;

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    private static function id(string $seed): string
    {
        return str_pad($seed, 32, '0');
    }

    public function test_create_returns_the_stored_sign_in_seen_when_it_began(): void
    {
        $signIn = $this->store()->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));

        $this->assertSame(self::id('a'), $signIn->id);
        $this->assertSame((string) $this->user()->getAuthIdentifier(), (string) $signIn->userId);
        $this->assertSame('2026-09-29 10:00:00', $signIn->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 10:00:00', $signIn->lastSeenAt->format('Y-m-d H:i:s'));
        $this->assertNull($signIn->ip);
        $this->assertNull($signIn->userAgent);
    }

    public function test_a_sign_in_is_found_by_its_id_and_nothing_else(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));

        $found = $store->find(self::id('a'));

        $this->assertNotNull($found);
        $this->assertSame((string) $this->user()->getAuthIdentifier(), (string) $found->userId);
        $this->assertSame('2026-09-29 10:00:00', $found->createdAt->format('Y-m-d H:i:s'));
        $this->assertNull($store->find(self::id('b')));
    }

    public function test_touch_records_when_and_from_where_it_was_last_seen(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));

        $store->touch(self::id('a'), self::at('2026-09-29 10:05:00'), '203.0.113.7', 'Firefox');

        $found = $store->find(self::id('a'));
        $this->assertNotNull($found);
        $this->assertSame('2026-09-29 10:00:00', $found->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-29 10:05:00', $found->lastSeenAt->format('Y-m-d H:i:s'));
        $this->assertSame('203.0.113.7', $found->ip);
        $this->assertSame('Firefox', $found->userAgent);
    }

    public function test_for_user_lists_only_that_users_sign_ins_most_recently_seen_first(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 09:00:00'));
        $store->create(self::id('b'), $this->otherUser(), self::at('2026-09-29 11:00:00'));
        $store->create(self::id('c'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->touch(self::id('a'), self::at('2026-09-29 12:00:00'), null, null);

        $ids = array_map(static fn (SignIn $s): string => $s->id, $store->forUser($this->user()));

        $this->assertSame([self::id('a'), self::id('c')], $ids);
    }

    public function test_revoke_removes_the_sign_in_and_says_whether_there_was_one(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));

        $this->assertTrue($store->revoke(self::id('a')));
        $this->assertNull($store->find(self::id('a')));
        $this->assertFalse($store->revoke(self::id('a')), 'already gone');
    }

    public function test_revoke_all_removes_every_sign_in_of_one_user_and_counts_them(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->create(self::id('b'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->create(self::id('c'), $this->otherUser(), self::at('2026-09-29 10:00:00'));

        $this->assertSame(2, $store->revokeAll($this->user()));
        $this->assertSame([], $store->forUser($this->user()));
        $this->assertCount(1, $store->forUser($this->otherUser()));
        $this->assertSame(0, $store->revokeAll($this->user()));
    }

    public function test_revoke_all_can_keep_one(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->create(self::id('b'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->create(self::id('c'), $this->user(), self::at('2026-09-29 10:00:00'));

        $this->assertSame(2, $store->revokeAll($this->user(), except: self::id('b')));
        $this->assertSame([self::id('b')], array_map(static fn (SignIn $s): string => $s->id, $store->forUser($this->user())));
    }

    public function test_prune_removes_what_was_last_seen_before_the_boundary(): void
    {
        $store = $this->store();
        $store->create(self::id('a'), $this->user(), self::at('2026-09-29 09:59:59'));
        $store->create(self::id('b'), $this->user(), self::at('2026-09-29 10:00:00'));
        $store->create(self::id('c'), $this->otherUser(), self::at('2026-09-29 08:00:00'));
        $store->touch(self::id('c'), self::at('2026-09-29 11:00:00'), null, null);

        $this->assertSame(1, $store->prune(self::at('2026-09-29 10:00:00')));
        $this->assertNull($store->find(self::id('a')));
        $this->assertNotNull($store->find(self::id('b')), 'seen exactly at the boundary');
        $this->assertNotNull($store->find(self::id('c')), 'old, but seen since');
    }
}
