<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\AssetController;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Renderer;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Events\RowCreated;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Http\CspNonce;
use Hydra\Admin\Tests\Support\RecordingDispatcher;
use Hydra\Http\Responder;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Hydra\View\PhpView;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What an application gets for registering the provider: the services the admin
 * needs, and the routes its modules compile to.
 */
#[CoversClass(AdminServiceProvider::class)]
final class AdminServiceProviderTest extends TestCase
{
    public function test_it_binds_what_the_generated_routes_will_ask_for(): void
    {
        $container = $this->container();

        (new AdminServiceProvider([UsersModule::class]))->register($container);

        foreach ([ModuleRegistry::class, Navigation::class, Chrome::class, Renderer::class, AdminController::class] as $service) {
            $this->assertInstanceOf($service, $container->get($service));
        }
    }

    public function test_the_prefix_it_was_given_is_the_one_the_modules_answer_at(): void
    {
        $container = $this->container();

        (new AdminServiceProvider([UsersModule::class], '/backstage'))->register($container);

        $this->assertSame('/backstage', $container->get(ModuleRegistry::class)->prefix());
        $this->assertSame(
            '/backstage/users',
            $container->get(ModuleRegistry::class)->root($container->get(ModuleRegistry::class)->find('users')),
        );
    }

    public function test_it_compiles_the_modules_into_routes_the_router_can_take(): void
    {
        $container = $this->container();
        $provider = new AdminServiceProvider([UsersModule::class]);
        $provider->register($container);

        $routes = $this->moduleRoutes($provider->routes($container));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertSame([AdminController::class, 'list'], $route['handler']);
            $this->assertStringStartsWith('/admin/users', $route['path']);
        }
    }

    public function test_the_middleware_it_was_given_is_carried_onto_every_module_route(): void
    {
        // A route the admin generated is otherwise indistinguishable from one
        // written by hand, so whatever guards the admin has to be attached here.
        $container = $this->container();
        $provider = new AdminServiceProvider([UsersModule::class], '/admin', ['RequireSignIn']);
        $provider->register($container);

        foreach ($this->moduleRoutes($provider->routes($container)) as $route) {
            $this->assertSame(['RequireSignIn'], $route['middleware']);
        }
    }

    public function test_the_assets_are_served_ahead_of_the_modules_and_outside_the_guard(): void
    {
        // First, because the router takes the first match and nothing stops a
        // module calling itself "assets". Unguarded, because a sign-in screen
        // has to be styled before anyone has signed in.
        $container = $this->container();
        $provider = new AdminServiceProvider([UsersModule::class], '/admin', ['RequireSignIn']);
        $provider->register($container);

        $first = $provider->routes($container)[0];

        $this->assertSame('/admin/assets/{asset}', $first['path']);
        $this->assertSame([AssetController::class, 'show'], $first['handler']);
        $this->assertSame([], $first['middleware']);
    }

    public function test_its_assets_directory_is_one_that_exists(): void
    {
        $this->assertDirectoryExists(AdminServiceProvider::assets());
    }

    /**
     * The routes a module produced, which is every route but the one serving
     * the package's own stylesheet and script.
     *
     * @param list<array<string, mixed>> $routes
     * @return list<array<string, mixed>>
     */
    private function moduleRoutes(array $routes): array
    {
        return array_values(array_filter(
            $routes,
            static fn (array $route): bool => $route['handler'][0] !== AssetController::class,
        ));
    }

    public function test_its_views_directory_is_one_that_exists(): void
    {
        $this->assertDirectoryExists(AdminServiceProvider::views());
    }

    /**
     * The controller is bound by hand rather than left to autowiring, and this
     * is what that buys. PHP-DI skips optional constructor parameters, so an
     * admin whose dispatcher arrived that way would announce nothing in every
     * application that had bound one, and nothing would say why.
     */
    public function test_the_controller_is_handed_the_dispatcher_the_application_bound(): void
    {
        $events = new RecordingDispatcher;
        $container = $this->container([
            CrudUsersModule::class => new CrudUsersModule,
            CrudUserSource::class => new CrudUserSource,
            EventDispatcherInterface::class => $events,
        ]);

        (new AdminServiceProvider([CrudUsersModule::class]))->register($container);
        $container->get(AdminController::class)->store($this->write('/admin/users/new', ['username' => 'linus']));

        $this->assertInstanceOf(RowCreated::class, $events->dispatched[0] ?? null);
    }

    public function test_an_application_with_no_dispatcher_still_gets_a_working_controller(): void
    {
        $container = $this->container([
            CrudUsersModule::class => new CrudUsersModule,
            CrudUserSource::class => $source = new CrudUserSource,
        ]);

        (new AdminServiceProvider([CrudUsersModule::class]))->register($container);

        $this->assertFalse($container->bound(EventDispatcherInterface::class));

        $container->get(AdminController::class)->store($this->write('/admin/users/new', ['username' => 'linus']));

        $this->assertSame('linus', $source->find('6')['username'] ?? null);
    }

    /** @param array<string, string> $body */
    private function write(string $path, array $body): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('POST', $path)->withParsedBody($body);
    }

    /** @param array<string, object> $services */
    private function container(array $services = []): FakeContainer
    {
        $psr17 = new Psr17Factory;

        return new FakeContainer([
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
            GateInterface::class => new AdminsOnlyGate(true),
            Responder::class => new Responder($psr17, $psr17),
            Validator::class => new Validator,
            ViewInterface::class => new PhpView(AdminServiceProvider::views(), new CspNonce),
            ...$services,
        ]);
    }
}
