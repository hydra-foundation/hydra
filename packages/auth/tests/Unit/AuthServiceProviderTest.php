<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\EmailChangeTokens;
use Hydra\Auth\EmailVerificationTokens;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\Events\TwoFactorChallenged;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\RecoveryCodes;
use Hydra\Auth\RequestGuard;
use Hydra\Auth\Testing\ArrayApiTokenStore;
use Hydra\Auth\Testing\ArrayTwoFactorStore;
use Hydra\Auth\Totp;
use Hydra\Auth\TwoFactorChallenge;
use Hydra\Cache\ArrayStore;
use Hydra\Http\ClientIpResolver;
use Hydra\Throttle\RateLimiter;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\PasswordResetTokens;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Environment;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Event\Testing\FakeDispatcher;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The wiring. Two of these are load-bearing in a way a unit test of the guard
 * cannot see: the dispatcher is optional, so a provider that resolved it
 * unconditionally would make hydrakit/event a hard dependency of signing in;
 * and the user provider is deliberately left unbound, so a provider that
 * guessed at one would put the framework inside the application's schema.
 */
#[CoversClass(AuthServiceProvider::class)]
final class AuthServiceProviderTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-auth-env-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment exports .env values to the real process environment,
        // and the real environment beats the file, so scrub every key this
        // test wrote or a stale export leaks into the next test.
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    public function test_it_binds_the_native_hasher_behind_the_interface(): void
    {
        $container = $this->register();

        $this->assertInstanceOf(NativeHasher::class, $container->get(HasherInterface::class));
    }

    public function test_the_hasher_uses_the_configured_work_factor(): void
    {
        // The cost is only observable in the hash it produces, and a provider
        // that built AuthConfig with its default would be invisible otherwise.
        $container = $this->register(['AUTH_HASH_COST' => '5']);

        $hash = $container->get(HasherInterface::class)->hash('correct horse');

        $this->assertNotFalse(password_get_info($hash)['options']['cost'] ?? false);
        $this->assertSame(5, password_get_info($hash)['options']['cost']);
    }

    public function test_an_unset_environment_still_yields_a_config(): void
    {
        $container = $this->register();

        $this->assertSame(12, $container->get(AuthConfig::class)->hashCost);
    }

    public function test_it_binds_the_request_guard_behind_the_interface(): void
    {
        $container = $this->register();

        $this->assertInstanceOf(RequestGuard::class, $container->get(GuardInterface::class));
        $this->assertSame($container->get(GuardInterface::class), $container->get(RequestGuard::class));
    }

    public function test_the_guard_is_shared_for_the_request(): void
    {
        // The guard caches the resolved user for the request. A second instance
        // would re-read the session and, worse, let the middleware and a
        // controller disagree about who is signed in.
        $container = $this->register();

        $this->assertSame(
            $container->get(GuardInterface::class),
            $container->get(GuardInterface::class),
        );
    }

    public function test_the_session_guard_by_its_class_is_the_one_the_request_guard_asks(): void
    {
        $container = $this->register();

        $container->get(SessionGuard::class)->login(new FakeUser('ada'));

        $this->assertInstanceOf(SessionGuard::class, $container->get(SessionGuard::class));
        $this->assertSame('ada', $container->get(GuardInterface::class)->id());
    }

    public function test_it_binds_reset_tokens_over_the_app_key_and_clock(): void
    {
        $container = $this->register(['AUTH_RESET_TTL' => '60']);
        $container->instance(Signer::class, Signer::fromHex(FixedSignerServiceProvider::KEY_HEX));
        $container->instance(ClockInterface::class, $clock = new FrozenClock);

        $tokens = $container->get(PasswordResetTokens::class);
        $token = $tokens->create(new FakeUser(1));
        $clock->advance('+61 seconds');

        $this->assertNull($tokens->resolve($token));
    }

    public function test_it_binds_verification_tokens_with_their_own_lifetime(): void
    {
        $container = $this->register(['AUTH_VERIFY_TTL' => '120']);
        $container->instance(Signer::class, Signer::fromHex(FixedSignerServiceProvider::KEY_HEX));
        $container->instance(ClockInterface::class, $clock = new FrozenClock);

        $tokens = $container->get(EmailVerificationTokens::class);
        $token = $tokens->create(new FakeUser('ada'));

        $clock->advance('+61 seconds');
        $this->assertNotNull($tokens->resolve($token));

        $clock->advance('+60 seconds');
        $this->assertNull($tokens->resolve($token));
    }

    public function test_it_binds_change_tokens_with_the_verification_lifetime(): void
    {
        $container = $this->register(['AUTH_VERIFY_TTL' => '120']);
        $container->instance(Signer::class, Signer::fromHex(FixedSignerServiceProvider::KEY_HEX));
        $container->instance(ClockInterface::class, $clock = new FrozenClock);

        $tokens = $container->get(EmailChangeTokens::class);
        // From the stored user: the token is bound to its password hash.
        $user = $container->get(UserProviderInterface::class)->byIdentifier('ada');
        $this->assertInstanceOf(FakeUser::class, $user);
        $token = $tokens->create($user, 'ada@elsewhere.test');

        $clock->advance('+61 seconds');
        $this->assertNotNull($tokens->resolve($token));

        $clock->advance('+60 seconds');
        $this->assertNull($tokens->resolve($token));
    }

    public function test_it_does_not_bind_a_user_provider(): void
    {
        // Auth owns no user storage. Binding a default here would be the
        // framework guessing at the application's schema.
        $container = new FakeContainer([Environment::class => $this->environment([])]);
        (new AuthServiceProvider)->register($container);

        $this->assertFalse($container->bound(UserProviderInterface::class));
    }

    public function test_signing_in_works_without_an_event_dispatcher(): void
    {
        // The dependency on PSR-14 is a soft one: an application that never
        // installed hydrakit/event must still be able to authenticate.
        $container = $this->register();

        $this->assertTrue($container->get(GuardInterface::class)->attempt('ada', 'secret'));
    }

    public function test_the_guard_announces_through_a_dispatcher_when_one_is_bound(): void
    {
        $events = new FakeDispatcher;
        $container = $this->register(events: $events);

        $container->get(GuardInterface::class)->attempt('ada', 'secret');

        $events->assertDispatched(LoggedIn::class);
    }

    public function test_nothing_is_built_until_it_is_asked_for(): void
    {
        $container = $this->register();

        $this->assertFalse($container->isResolved(AuthConfig::class));
        $this->assertFalse($container->isResolved(HasherInterface::class));
        $this->assertFalse($container->isResolved(GuardInterface::class));
    }

    public function test_it_binds_api_tokens_over_the_apps_store_and_the_clock(): void
    {
        $container = $this->register();
        $container->instance(ClockInterface::class, new FrozenClock('2026-09-25 10:00:00'));
        $container->instance(ApiTokenStoreInterface::class, new ArrayApiTokenStore);
        $tokens = $container->get(ApiTokens::class);

        $issued = $tokens->issue(new FakeUser('ada'), 'CLI');

        $this->assertSame('2026-09-25 10:00:00', $issued->token->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('ada', $tokens->authenticate($issued->plain)?->user->getAuthIdentifier());
        $this->assertSame($tokens, $container->get(ApiTokens::class));
    }

    public function test_api_tokens_ask_for_no_store_until_they_are_used(): void
    {
        $container = $this->register();

        $this->assertFalse($container->isResolved(ApiTokens::class));
        $this->assertFalse($container->bound(ApiTokenStoreInterface::class));
    }

    public function test_it_binds_the_two_factor_pieces_over_the_clock_and_hasher(): void
    {
        $container = $this->register();
        $container->instance(ClockInterface::class, $clock = new FrozenClock('@59'));
        $container->instance(TwoFactorStoreInterface::class, $store = new ArrayTwoFactorStore);
        $container->instance(RateLimiter::class, new RateLimiter(ArrayStore::withClock($clock), new ClientIpResolver));
        $container->instance(EventDispatcherInterface::class, $events = new FakeDispatcher);
        $store->enable(new FakeUser('ada'), 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', []);

        $this->assertSame('287082', $container->get(Totp::class)->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'));
        $this->assertCount(2, $container->get(RecoveryCodes::class)->generate(2)['hashes']);

        $challenge = $container->get(TwoFactorChallenge::class);
        $challenge->begin(new FakeUser('ada'));

        $this->assertTrue($challenge->verify('287082'));
        $this->assertSame('ada', $container->get(GuardInterface::class)->id());
        $this->assertSame([TwoFactorChallenged::class, LoggedIn::class], $events->types());
    }

    public function test_a_challenge_without_a_dispatcher_announces_nothing_and_still_works(): void
    {
        $container = $this->register();
        $container->instance(ClockInterface::class, $clock = new FrozenClock('@59'));
        $container->instance(TwoFactorStoreInterface::class, $store = new ArrayTwoFactorStore);
        $container->instance(RateLimiter::class, new RateLimiter(ArrayStore::withClock($clock), new ClientIpResolver));
        $store->enable(new FakeUser('ada'), 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', []);

        $challenge = $container->get(TwoFactorChallenge::class);
        $challenge->begin(new FakeUser('ada'));

        $this->assertTrue($challenge->verify('287082'));
    }

    public function test_a_challenge_without_a_store_says_which_binding_is_missing(): void
    {
        $container = $this->register();

        $this->expectException(NotFoundExceptionInterface::class);
        $this->expectExceptionMessageMatches('/TwoFactorStoreInterface/');

        $container->get(TwoFactorChallenge::class);
    }

    public function test_a_guard_built_without_a_user_provider_says_which_binding_is_missing(): void
    {
        $container = new FakeContainer([
            Environment::class => $this->environment([]),
            SessionInterface::class => $this->session(),
        ]);
        (new AuthServiceProvider)->register($container);

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get(GuardInterface::class);
    }

    /** @param array<string, string> $env */
    private function register(array $env = [], ?EventDispatcherInterface $events = null): FakeContainer
    {
        $services = [
            Environment::class => $this->environment($env),
            SessionInterface::class => $this->session(),
            // At the cheapest cost bcrypt takes; the guard verifies against it for real.
            UserProviderInterface::class => (new ArrayUserProvider)
                ->add('ada', new FakeUser('ada', password_hash('secret', PASSWORD_DEFAULT, ['cost' => 4]))),
        ];

        if ($events !== null) {
            $services[EventDispatcherInterface::class] = $events;
        }

        $container = new FakeContainer($services);
        (new AuthServiceProvider)->register($container);

        return $container;
    }

    /** Inside its lifecycle, which is where StartSessionMiddleware would hand it over. */
    private function session(): ArraySessionStore
    {
        $session = new ArraySessionStore;
        $session->start();

        return $session;
    }

    /** @param array<string, string> $values */
    private function environment(array $values): Environment
    {
        $lines = '';
        foreach ($values as $key => $value) {
            $this->written[] = $key;
            $lines .= "{$key}={$value}\n";
        }

        file_put_contents($this->dir . '/.env', $lines);

        return new Environment($this->dir);
    }
}
