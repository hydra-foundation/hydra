<?php

declare(strict_types=1);

namespace Hydra\Tests\Fixture;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Csrf\Testing\CarriesCsrfToken;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Event\EventServiceProvider;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use Hydra\Tests\Fixture\Entities\Role;
use Hydra\Tests\Fixture\Providers\FixtureServiceProvider;
use Hydra\Throttle\ThrottleConfig;
use Hydra\Throttle\ThrottleServiceProvider;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Auth\AuthenticateMiddleware;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * A booted fixture application, and the handful of things a flow test needs to
 * drive one.
 *
 * This is the harness the integration flows used to hand-roll a copy of each.
 * That mattered less as duplication than as drift: eleven providers in a fixed
 * order, a logger that has to be bound *before* boot, and a hash cost that has
 * to be lowered or the suite spends its time in bcrypt. Each flow that got one
 * of those subtly wrong was still green, and testing something slightly
 * different from the others.
 */
final class Fixture
{
    /** The password every seeded account holds, so a flow never has to say so. */
    public const PASSWORD = 'correct-horse-battery-staple';

    private function __construct(
        private readonly ContainerInterface $container,
        private readonly CapturingLogger $log,
        private readonly PDO $pdo,
    ) {}

    /**
     * The whole composition root, in the order an application boots it.
     *
     * The test doubles are registered ahead of the providers they stand in for
     * — a session that needs no session_start(), a store that needs no Redis, a
     * signer that needs no APP_KEY — because the Environment here deliberately
     * has no .env to read any of that from.
     */
    public static function boot(?ThrottleConfig $throttle = null): self
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        $application = (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new EventServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(new HttpServiceProvider(
                controllers: FixtureServiceProvider::CONTROLLERS,
                middleware: FixtureServiceProvider::MIDDLEWARE,
                // Scanned live, and written nowhere: a cached route table is a
                // file two tests would share.
                routeCacheEnabled: false,
                routeCachePath: '/dev/null',
            ))
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new FixtureServiceProvider)
            ->register(new AdminServiceProvider(
                modules: FixtureServiceProvider::MODULES,
                prefix: '/admin',
                middleware: [AuthenticateMiddleware::class],
            ));

        // Before boot(), not after: boot() builds the listeners with whatever
        // LoggerInterface resolves to, so a logger swapped in afterwards hears
        // nothing and the flow reads an empty log with no clue why.
        $log = new CapturingLogger;
        $container->instance(LoggerInterface::class, $log);

        if ($throttle !== null) {
            $container->instance(ThrottleConfig::class, $throttle);
        }

        $application->boot();

        // Four rounds rather than the shipped cost: every flow that signs in
        // pays this, and none of them is about bcrypt's work factor.
        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = Schema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        return new self($container, $log, $pdo);
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function log(): CapturingLogger
    {
        return $this->log;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** A client whose unsafe requests carry the session's CSRF token. */
    public function http(): Client
    {
        return Client::for($this->container, [CarriesCsrfToken::for($this->container)]);
    }

    /** @return mixed */
    public function get(string $id)
    {
        return $this->container->get($id);
    }

    /** Returns the id of the seeded row. */
    public function seed(string $username, Role $role = Role::DEFAULT): int
    {
        $this->pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
            ->execute([
                $username,
                $this->container->get(HasherInterface::class)->hash(self::PASSWORD),
                $role->value,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function login(string $username, string $password = self::PASSWORD): TestResponse
    {
        return $this->http()->post('/login', ['username' => $username, 'password' => $password]);
    }
}
