<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Renderer;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Http\Responder;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * What an application gets for registering the provider: the services the admin
 * needs, and the routes its modules compile to.
 */
final class AdminServiceProviderTest extends TestCase
{
    public function test_it_binds_what_the_generated_routes_will_ask_for(): void
    {
        $container = $this->container();

        (new AdminServiceProvider([UsersModule::class]))->register($container);

        foreach ([ModuleRegistry::class, Navigation::class, Chrome::class, Renderer::class] as $service) {
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

        $routes = $provider->routes($container);

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertSame([AdminController::class, 'list'], $route['handler']);
            $this->assertStringStartsWith('/admin/users', $route['path']);
        }
    }

    public function test_the_middleware_it_was_given_is_carried_onto_every_route(): void
    {
        // A route the admin generated is otherwise indistinguishable from one
        // written by hand, so whatever guards the admin has to be attached here.
        $container = $this->container();
        $provider = new AdminServiceProvider([UsersModule::class], '/admin', ['RequireSignIn']);
        $provider->register($container);

        foreach ($provider->routes($container) as $route) {
            $this->assertSame(['RequireSignIn'], $route['middleware']);
        }
    }

    public function test_its_views_directory_is_one_that_exists(): void
    {
        $this->assertDirectoryExists(AdminServiceProvider::views());
    }

    private function container(): ArrayContainer
    {
        $psr17 = new Psr17Factory;

        return new ArrayContainer([
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
            GateInterface::class => new AdminsOnlyGate(true),
            Responder::class => new Responder($psr17, $psr17),
            ViewInterface::class => new PhpView(AdminServiceProvider::views()),
        ]);
    }
}
