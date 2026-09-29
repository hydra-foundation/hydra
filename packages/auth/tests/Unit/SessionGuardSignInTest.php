<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use DateTimeImmutable;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\SignIn;
use Hydra\Auth\Testing\ArraySignInStore;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeHasher;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Session\Stores\ArraySessionStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The guard with a sign-in store bound: a record written at login and checked
 * on every request after, so that deleting it signs that browser out. Each
 * test that ends in "signed out" builds a fresh guard over the same session,
 * because that is what the next request is.
 */
#[CoversClass(SessionGuard::class)]
final class SessionGuardSignInTest extends TestCase
{
    private ArrayUserProvider $provider;
    private ArraySessionStore $session;
    private ArraySignInStore $signIns;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->provider = (new ArrayUserProvider)
            ->add('ada', new FakeUser(1, 'hashed:ada'))
            ->add('bob', new FakeUser(2, 'hashed:bob'));
        $this->session = new ArraySessionStore;
        $this->session->start();
        $this->signIns = new ArraySignInStore;
        $this->clock = new FrozenClock('2026-09-29 10:00:00');
    }

    public function test_a_store_without_a_clock_is_refused_with_the_fix(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SessionGuard keeps sign-in records with a clock; bind Psr\Clock\ClockInterface (ClockServiceProvider does).');

        new SessionGuard($this->session, $this->provider, new FakeHasher, null, $this->signIns);
    }

    public function test_login_records_the_sign_in_at_the_clocks_time(): void
    {
        $guard = $this->guard();

        $guard->login($this->ada());

        $signIns = $this->signIns->forUser($this->ada());
        $this->assertCount(1, $signIns);
        $this->assertSame(32, strlen($signIns[0]->id));
        $this->assertTrue(ctype_xdigit($signIns[0]->id));
        $this->assertEquals($this->clock->now(), $signIns[0]->createdAt);
        $this->assertSame($signIns[0], $guard->signIn());
    }

    public function test_the_sign_in_id_is_not_the_session_id(): void
    {
        $this->guard()->login($this->ada());

        $this->assertNotSame($this->session->id(), $this->signIns->forUser($this->ada())[0]->id);
    }

    public function test_each_login_gets_an_id_of_its_own(): void
    {
        $this->guard()->login($this->ada());
        $this->guard($this->freshSession())->login($this->ada());

        $ids = array_map(static fn (SignIn $s): string => $s->id, $this->signIns->forUser($this->ada()));

        $this->assertCount(2, array_unique($ids));
    }

    public function test_a_recorded_sign_in_stays_signed_in_on_the_next_request(): void
    {
        $this->guard()->login($this->ada());

        $next = $this->guard();

        $this->assertSame(1, $next->user()?->getAuthIdentifier());
        $this->assertSame($this->signIns->forUser($this->ada())[0]->id, $next->signIn()?->id);
    }

    public function test_a_revoked_sign_in_is_signed_out_and_its_session_cleared(): void
    {
        $this->guard()->login($this->ada());
        $this->session->set('cart', ['book']);
        $this->signIns->revoke($this->signIns->forUser($this->ada())[0]->id);

        $next = $this->guard();

        $this->assertNull($next->user());
        $this->assertFalse($next->check());
        $this->assertNull($next->signIn());
        $this->assertNull($this->session->get('cart'), 'what the account stored goes with it');
        $this->assertNull($next->id());
    }

    public function test_a_sign_in_recorded_for_someone_else_is_signed_out(): void
    {
        $this->guard()->login($this->ada());
        $id = $this->signIns->forUser($this->ada())[0]->id;
        $this->signIns->revoke($id);
        $this->signIns->create($id, $this->bob(), $this->clock->now());

        $this->assertNull($this->guard()->user());
        $this->assertNotNull($this->signIns->find($id), "bob's record is not ada's to revoke");
    }

    public function test_a_signed_in_session_with_no_record_yet_is_adopted_once(): void
    {
        // Signed in before the store was bound: an id and a stamp, no record.
        (new SessionGuard($this->session, $this->provider, new FakeHasher))->login($this->ada());

        $first = $this->guard();
        $this->assertSame(1, $first->user()?->getAuthIdentifier());
        $this->assertCount(1, $this->signIns->forUser($this->ada()));
        $this->assertSame($this->signIns->forUser($this->ada())[0], $first->signIn());

        $this->assertSame(1, $this->guard()->user()?->getAuthIdentifier());
        $this->assertCount(1, $this->signIns->forUser($this->ada()), 'adopted once, then checked');
    }

    public function test_an_anonymous_session_writes_no_record(): void
    {
        $guard = $this->guard();

        $this->assertNull($guard->user());
        $this->assertNull($guard->signIn());
        $this->assertSame([], $this->signIns->forUser($this->ada()));
    }

    public function test_logging_in_again_ends_the_sign_in_the_session_held(): void
    {
        $guard = $this->guard();
        $guard->login($this->ada());
        $first = $guard->signIn()->id ?? self::fail('no sign-in');

        $guard->login($this->bob());

        $this->assertNull($this->signIns->find($first));
        $this->assertCount(1, $this->signIns->forUser($this->bob()));
        $this->assertSame([], $this->signIns->forUser($this->ada()));
    }

    public function test_logout_ends_the_sign_in(): void
    {
        $guard = $this->guard();
        $guard->login($this->ada());

        $guard->logout();

        $this->assertSame([], $this->signIns->forUser($this->ada()));
        $this->assertNull($guard->signIn());
    }

    public function test_logout_with_nobody_signed_in_revokes_nothing(): void
    {
        $this->guard($this->freshSession())->login($this->ada());

        $this->guard()->logout();

        $this->assertCount(1, $this->signIns->forUser($this->ada()));
    }

    /**
     * A password changed elsewhere ends this session as it always has; with a
     * store its record goes too, so a list of sign-ins shows only live ones.
     */
    public function test_a_session_ended_by_a_password_change_loses_its_record(): void
    {
        $this->guard()->login($this->ada());
        $this->provider->add('ada', new FakeUser(1, 'hashed:new'));

        $this->assertNull($this->guard()->user());
        $this->assertSame([], $this->signIns->forUser($this->ada()));
    }

    public function test_refresh_after_a_password_change_ends_every_other_sign_in_and_keeps_this_one(): void
    {
        $this->guard($this->freshSession())->login($this->ada());
        $this->guard($this->freshSession())->login($this->ada());
        $this->guard($this->freshSession())->login($this->bob());
        $guard = $this->guard();
        $guard->login($this->ada());
        $mine = $guard->signIn()->id ?? self::fail('no sign-in');

        $this->provider->add('ada', $changed = new FakeUser(1, 'hashed:new'));
        $guard->refresh($changed);

        $this->assertSame([$mine], array_map(static fn (SignIn $s): string => $s->id, $this->signIns->forUser($this->ada())));
        $this->assertCount(1, $this->signIns->forUser($this->bob()), "bob's sign-ins are not ada's");
        $this->assertSame(1, $this->guard()->user()?->getAuthIdentifier(), 'this one still works next request');
    }

    public function test_refresh_for_anything_but_a_password_ends_nothing(): void
    {
        $this->guard($this->freshSession())->login($this->ada());
        $guard = $this->guard();
        $guard->login($this->ada());

        $guard->refresh($this->ada());

        $this->assertCount(2, $this->signIns->forUser($this->ada()));
    }

    /** A store that can't answer must never be read as "still signed in". */
    public function test_a_store_that_fails_to_answer_fails_the_request(): void
    {
        $this->guard()->login($this->ada());
        $broken = new class ($this->signIns) implements SignInStoreInterface {
            public function __construct(private readonly ArraySignInStore $inner) {}

            public function create(string $id, AuthenticatableInterface $user, DateTimeImmutable $at): SignIn
            {
                return $this->inner->create($id, $user, $at);
            }

            public function find(string $id): ?SignIn
            {
                throw new RuntimeException('database gone');
            }

            public function forUser(AuthenticatableInterface $user): array
            {
                return $this->inner->forUser($user);
            }

            public function touch(string $id, DateTimeImmutable $at, ?string $ip, ?string $userAgent): void {}

            public function revoke(string $id): bool
            {
                return $this->inner->revoke($id);
            }

            public function revokeAll(AuthenticatableInterface $user, ?string $except = null): int
            {
                return $this->inner->revokeAll($user, $except);
            }

            public function prune(DateTimeImmutable $before): int
            {
                return 0;
            }
        };

        $this->expectException(RuntimeException::class);

        (new SessionGuard($this->session, $this->provider, new FakeHasher, null, $broken, $this->clock))->user();
    }

    public function test_without_a_store_nothing_is_recorded_and_sign_in_is_null(): void
    {
        $guard = new SessionGuard($this->session, $this->provider, new FakeHasher);

        $guard->login($this->ada());

        $this->assertNull($guard->signIn());
        $this->assertSame(1, $guard->user()?->getAuthIdentifier());
    }

    private function guard(?ArraySessionStore $session = null): SessionGuard
    {
        return new SessionGuard($session ?? $this->session, $this->provider, new FakeHasher, null, $this->signIns, $this->clock);
    }

    private function freshSession(): ArraySessionStore
    {
        $session = new ArraySessionStore;
        $session->start();

        return $session;
    }

    private function ada(): AuthenticatableInterface
    {
        return $this->provider->byUsername('ada') ?? self::fail('ada is not seeded');
    }

    private function bob(): AuthenticatableInterface
    {
        return $this->provider->byUsername('bob') ?? self::fail('bob is not seeded');
    }
}
