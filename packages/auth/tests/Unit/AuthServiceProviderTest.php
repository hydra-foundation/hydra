<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\SessionGuard;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Environment;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Stores\ArraySessionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    public function test_it_binds_the_session_guard_behind_the_interface(): void
    {
        $container = $this->register();

        $this->assertInstanceOf(SessionGuard::class, $container->get(GuardInterface::class));
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
        $events = new RecordingEvents;
        $container = $this->register(events: $events);

        $container->get(GuardInterface::class)->attempt('ada', 'secret');

        $this->assertContainsOnlyInstancesOf(LoggedIn::class, array_filter(
            $events->dispatched,
            static fn (object $event) => $event instanceof LoggedIn,
        ));
        $this->assertNotSame([], $events->dispatched);
    }

    public function test_nothing_is_built_until_it_is_asked_for(): void
    {
        $container = $this->register();

        $this->assertFalse($container->isResolved(AuthConfig::class));
        $this->assertFalse($container->isResolved(HasherInterface::class));
        $this->assertFalse($container->isResolved(GuardInterface::class));
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
            UserProviderInterface::class => new OneUserProvider,
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

/** One user, whose password hash is built at the cheapest cost bcrypt takes. */
final class OneUserProvider implements UserProviderInterface
{
    private readonly SoleUser $user;

    public function __construct()
    {
        $this->user = new SoleUser('ada', password_hash('secret', PASSWORD_DEFAULT, ['cost' => 4]));
    }

    public function byIdentifier(int|string $id): ?AuthenticatableInterface
    {
        return $id === 'ada' ? $this->user : null;
    }

    public function byUsername(string $username): ?AuthenticatableInterface
    {
        return $username === 'ada' ? $this->user : null;
    }
}

final class SoleUser implements AuthenticatableInterface
{
    public function __construct(
        private readonly int|string $id,
        private readonly string $hash,
    ) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->hash;
    }
}

final class RecordingEvents implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}
