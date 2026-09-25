<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use DateTimeImmutable;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\AuthenticatedToken;
use Hydra\Auth\IssuedApiToken;
use Hydra\Auth\Testing\ArrayApiTokenStore;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Testing\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiTokens::class)]
#[CoversClass(IssuedApiToken::class)]
#[CoversClass(AuthenticatedToken::class)]
final class ApiTokensTest extends TestCase
{
    private ArrayApiTokenStore $store;
    private ArrayUserProvider $users;
    private FrozenClock $clock;
    private ApiTokens $tokens;

    protected function setUp(): void
    {
        $this->store = new ArrayApiTokenStore;
        $this->users = (new ArrayUserProvider)->add('ada', new FakeUser('ada'));
        $this->clock = new FrozenClock('2026-09-25 10:00:00');
        $this->tokens = new ApiTokens($this->store, $this->users, $this->clock);
    }

    public function test_an_issued_token_is_prefixed_and_carries_32_random_bytes(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI');

        $this->assertMatchesRegularExpression('/^hyd_[A-Za-z0-9_-]{43}$/', $issued->plain);
        $this->assertNotSame($issued->plain, $this->tokens->issue(new FakeUser('ada'), 'CLI')->plain);
    }

    public function test_the_store_holds_only_the_sha256_of_the_token(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI');

        $this->assertSame(hash('sha256', $issued->plain), $issued->token->hash);
        $this->assertSame('ada', $this->store->findByHash(hash('sha256', $issued->plain))?->userId);
    }

    public function test_the_token_is_stamped_with_the_clock_and_its_expiry(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI', new DateTimeImmutable('2026-12-24 10:00:00'));

        $this->assertEquals($this->clock->now(), $issued->token->createdAt);
        $this->assertEquals(new DateTimeImmutable('2026-12-24 10:00:00'), $issued->token->expiresAt);
    }

    public function test_the_name_is_trimmed(): void
    {
        $this->assertSame('CLI', $this->tokens->issue(new FakeUser('ada'), '  CLI  ')->token->name);
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'over 100 characters' => [str_repeat('é', 101)];
    }

    #[DataProvider('badNames')]
    public function test_a_bad_name_is_refused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tokens->issue(new FakeUser('ada'), $name);
    }

    public function test_a_name_of_exactly_100_characters_is_accepted(): void
    {
        $this->assertSame(100, mb_strlen($this->tokens->issue(new FakeUser('ada'), str_repeat('é', 100))->token->name));
    }

    public function test_an_expiry_that_is_not_in_the_future_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tokens->issue(new FakeUser('ada'), 'CLI', $this->clock->now());
    }

    public function test_a_live_token_authenticates_its_owner_and_records_the_use(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI');
        $this->clock->advance('+1 hour');

        $authenticated = $this->tokens->authenticate($issued->plain);

        $this->assertNotNull($authenticated);
        $this->assertSame('ada', $authenticated->user->getAuthIdentifier());
        $this->assertEquals($issued->token->id, $authenticated->token->id);
        $this->assertEquals($this->clock->now(), $this->store->findByHash($issued->token->hash)?->lastUsedAt);
    }

    public function test_a_token_before_its_expiry_still_authenticates(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI', new DateTimeImmutable('2026-09-25 11:00:00'));
        $this->clock->set('2026-09-25 10:59:59');

        $this->assertNotNull($this->tokens->authenticate($issued->plain));
    }

    public function test_an_expired_token_authenticates_nobody_and_is_not_touched(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI', new DateTimeImmutable('2026-09-25 11:00:00'));
        $this->clock->set('2026-09-25 11:00:00');

        $this->assertNull($this->tokens->authenticate($issued->plain));
        $this->assertNull($this->store->findByHash($issued->token->hash)?->lastUsedAt);
    }

    public function test_a_revoked_token_authenticates_nobody(): void
    {
        $issued = $this->tokens->issue(new FakeUser('ada'), 'CLI');
        $this->store->revoke(new FakeUser('ada'), $issued->token->id);

        $this->assertNull($this->tokens->authenticate($issued->plain));
    }

    public function test_a_token_whose_owner_is_gone_authenticates_nobody_and_is_not_touched(): void
    {
        $issued = $this->tokens->issue(new FakeUser('grace'), 'CLI');

        $this->assertNull($this->tokens->authenticate($issued->plain));
        $this->assertNull($this->store->findByHash($issued->token->hash)?->lastUsedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'no prefix' => [str_repeat('a', 47)];
        yield 'too short' => ['hyd_' . str_repeat('a', 42)];
        yield 'too long' => ['hyd_' . str_repeat('a', 44)];
        yield 'outside base64url' => ['hyd_' . str_repeat('a', 42) . '='];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_token_is_refused_before_any_lookup(string $plain): void
    {
        // A store row under the malformed value's own hash proves no lookup ran.
        $this->store->create(new FakeUser('ada'), 'planted', hash('sha256', $plain), $this->clock->now(), null);

        $this->assertNull($this->tokens->authenticate($plain));
    }

    public function test_an_unknown_token_authenticates_nobody(): void
    {
        $this->assertNull($this->tokens->authenticate('hyd_' . str_repeat('a', 43)));
    }
}
