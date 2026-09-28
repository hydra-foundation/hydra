<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\AssetController;
use Hydra\Admin\FileController;
use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Files\Reference;
use Hydra\Admin\Tests\Support\HoldingSource;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Renderer;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Events\RowCreated;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Disks;
use LogicException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Nyholm\Psr7\UploadedFile;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Http\CspNonce;
use Hydra\Event\Testing\FakeDispatcher;
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

    public function test_private_files_are_served_ahead_of_the_modules_and_behind_the_guard(): void
    {
        // Behind it, because a private file is one nobody signed out may read;
        // ahead of the modules, because a module is free to call itself "files".
        $container = $this->container();
        $provider = new AdminServiceProvider([UsersModule::class], '/admin', ['RequireSignIn']);
        $provider->register($container);

        $routes = $provider->routes($container);
        $files = array_values(array_filter($routes, static fn (array $route): bool => $route['handler'] === [FileController::class, 'show']));

        $this->assertCount(1, $files);
        $this->assertSame('GET', $files[0]['method']);
        $this->assertSame('/admin/file', $files[0]['path']);
        $this->assertSame(['RequireSignIn'], $files[0]['middleware']);
        $this->assertLessThan(
            array_search($this->moduleRoutes($routes)[0], $routes, true),
            array_search($files[0], $routes, true),
        );
    }

    public function test_the_file_controller_is_built_with_or_without_disks(): void
    {
        $container = $this->container([StreamFactoryInterface::class => new Psr17Factory, ResponseFactoryInterface::class => new Psr17Factory]);
        (new AdminServiceProvider([]))->register($container);

        $this->assertInstanceOf(FileController::class, $container->get(FileController::class));
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
            static fn (array $route): bool => !in_array($route['handler'][0], [AssetController::class, FileController::class], true),
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
        $events = new FakeDispatcher;
        $container = $this->container([
            CrudUsersModule::class => new CrudUsersModule,
            CrudUserSource::class => new CrudUserSource,
            EventDispatcherInterface::class => $events,
        ]);

        (new AdminServiceProvider([CrudUsersModule::class]))->register($container);
        $container->get(AdminController::class)->store($this->write('/admin/users/new', ['username' => 'linus']));

        $this->assertInstanceOf(RowCreated::class, $events->dispatched()[0] ?? null);
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

    public function test_the_controller_stores_files_on_the_disks_the_application_bound(): void
    {
        $disks = new TemporaryDisks;
        $container = $this->container([
            AvatarUsersModule::class => new AvatarUsersModule,
            CrudUserSource::class => $source = new CrudUserSource,
            Disks::class => $disks->disks,
            PublicStorageInterface::class => $disks->disks->public(),
        ]);

        (new AdminServiceProvider([AvatarUsersModule::class]))->register($container);
        $request = $this->write('/admin/users/new', ['username' => 'linus'])->withUploadedFiles([
            'avatar' => new UploadedFile(Stream::create(base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            )), 68, UPLOAD_ERR_OK, 'me.png', 'image/png'),
        ]);
        $container->get(AdminController::class)->store($request);

        $this->assertStringStartsWith('private:avatars/', (string) ($source->find('6')['avatar'] ?? ''));
        $this->assertSame(1, $disks->count());
        $disks->remove();
    }

    public function test_a_disks_class_the_container_could_autowire_is_not_taken_for_bound_disks(): void
    {
        // What PHP-DI does with a concrete class nobody registered: calls it
        // bound, and then fails to build it. The controller must not ask.
        $container = $this->container([
            CrudUsersModule::class => new CrudUsersModule,
            CrudUserSource::class => $source = new CrudUserSource,
        ]);
        $container->singleton(Disks::class, static fn () => throw new LogicException('Disks was built.'));

        (new AdminServiceProvider([CrudUsersModule::class]))->register($container);
        $container->get(AdminController::class)->store($this->write('/admin/users/new', ['username' => 'linus']));

        $this->assertSame('linus', $source->find('6')['username'] ?? null);
    }

    public function test_file_references_read_the_modules_and_the_holders_the_application_listed(): void
    {
        $disks = new TemporaryDisks;
        $users = new CrudUserSource;
        $users->update('2', ['avatar' => 'private:avatars/0123456789abcdef0123456789abcdef.png', 'avatar_name' => 'Grace.png']);
        $blog = new Reference('public:blog/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png', 'BlogImages');
        $container = $this->container([
            AvatarUsersModule::class => new AvatarUsersModule,
            CrudUserSource::class => $users,
            HoldingSource::class => new HoldingSource([$blog]),
            Disks::class => $disks->disks,
            PublicStorageInterface::class => $disks->disks->public(),
        ]);

        (new AdminServiceProvider([AvatarUsersModule::class], fileHolders: [HoldingSource::class]))->register($container);
        $references = $container->get(FileReferences::class);

        $this->assertEquals(
            [new Reference('private:avatars/0123456789abcdef0123456789abcdef.png', 'users', '2', 'avatar', 'Grace.png')],
            $references->to('private:avatars/0123456789abcdef0123456789abcdef.png'),
        );
        $this->assertEquals([$blog], $references->to($blog->key));
        $disks->remove();
    }

    public function test_file_references_without_disks_say_which_provider_is_missing(): void
    {
        $container = $this->container();
        (new AdminServiceProvider([UsersModule::class]))->register($container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Register Hydra\\Filesystem\\FilesystemServiceProvider ahead of the AdminServiceProvider.');

        $container->get(FileReferences::class);
    }

    public function test_a_listed_file_holder_that_is_not_one_is_refused_by_name(): void
    {
        $disks = new TemporaryDisks;
        $container = $this->container([
            Disks::class => $disks->disks,
            PublicStorageInterface::class => $disks->disks->public(),
        ]);
        (new AdminServiceProvider([UsersModule::class], fileHolders: [ArraySource::class]))->register($container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ArraySource::class . ' must implement ' . FileHolderInterface::class);

        $container->get(FileReferences::class);
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
