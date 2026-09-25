<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use DateTimeImmutable;
use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use PHPUnit\Framework\TestCase;

/**
 * What every {@see ApiTokenStoreInterface} has to do, the fake included.
 * Extend it with a store over your own tables and two users it can write to.
 */
abstract class ApiTokenStoreContractTestCase extends TestCase
{
    abstract protected function store(): ApiTokenStoreInterface;

    /** A user the store can write to, with no tokens yet. */
    abstract protected function user(): AuthenticatableInterface;

    /** A second such user, to show one account's tokens stay its own. */
    abstract protected function otherUser(): AuthenticatableInterface;

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    private static function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }

    public function test_create_returns_the_stored_token(): void
    {
        $token = $this->store()->create(
            $this->user(),
            'CLI',
            self::hash('a'),
            self::at('2026-09-25 10:00:00'),
            self::at('2026-12-24 10:00:00'),
        );

        $this->assertSame($this->user()->getAuthIdentifier(), $token->userId);
        $this->assertSame('CLI', $token->name);
        $this->assertSame(self::hash('a'), $token->hash);
        $this->assertSame('2026-09-25 10:00:00', $token->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-24 10:00:00', $token->expiresAt?->format('Y-m-d H:i:s'));
        $this->assertNull($token->lastUsedAt);
    }

    public function test_a_token_is_found_by_its_hash_and_nothing_else(): void
    {
        $store = $this->store();
        $created = $store->create($this->user(), 'CLI', self::hash('a'), self::at('2026-09-25 10:00:00'), null);

        $found = $store->findByHash(self::hash('a'));

        $this->assertNotNull($found);
        $this->assertEquals($created->id, $found->id);
        $this->assertNull($found->expiresAt);
        $this->assertNull($store->findByHash(self::hash('b')));
    }

    public function test_for_user_lists_only_that_users_tokens_newest_first(): void
    {
        $store = $this->store();
        $store->create($this->user(), 'first', self::hash('a'), self::at('2026-09-25 10:00:00'), null);
        $store->create($this->otherUser(), 'theirs', self::hash('b'), self::at('2026-09-25 11:00:00'), null);
        $store->create($this->user(), 'second', self::hash('c'), self::at('2026-09-25 12:00:00'), null);

        $names = array_map(fn (ApiToken $t) => $t->name, $store->forUser($this->user()));

        $this->assertSame(['second', 'first'], $names);
    }

    public function test_touch_records_the_last_use(): void
    {
        $store = $this->store();
        $token = $store->create($this->user(), 'CLI', self::hash('a'), self::at('2026-09-25 10:00:00'), null);

        $store->touch($token->id, self::at('2026-09-26 08:30:00'));

        $this->assertSame('2026-09-26 08:30:00', $store->findByHash(self::hash('a'))?->lastUsedAt?->format('Y-m-d H:i:s'));
    }

    public function test_revoke_removes_the_token_only_for_its_owner(): void
    {
        $store = $this->store();
        $token = $store->create($this->user(), 'CLI', self::hash('a'), self::at('2026-09-25 10:00:00'), null);

        $this->assertFalse($store->revoke($this->otherUser(), $token->id), "another user's token");
        $this->assertNotNull($store->findByHash(self::hash('a')));

        $this->assertTrue($store->revoke($this->user(), $token->id));
        $this->assertNull($store->findByHash(self::hash('a')));
        $this->assertFalse($store->revoke($this->user(), $token->id), 'already gone');
    }

    public function test_revoke_all_removes_every_token_of_one_user_and_counts_them(): void
    {
        $store = $this->store();
        $store->create($this->user(), 'one', self::hash('a'), self::at('2026-09-25 10:00:00'), null);
        $store->create($this->user(), 'two', self::hash('b'), self::at('2026-09-25 10:00:00'), null);
        $store->create($this->otherUser(), 'theirs', self::hash('c'), self::at('2026-09-25 10:00:00'), null);

        $this->assertSame(2, $store->revokeAll($this->user()));
        $this->assertSame([], $store->forUser($this->user()));
        $this->assertCount(1, $store->forUser($this->otherUser()));
        $this->assertSame(0, $store->revokeAll($this->user()));
    }
}
