<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Files\FileReferences;
use Hydra\Admin\Live\LiveAdmin;
use Hydra\Admin\Live\ModuleChanges;
use Hydra\Admin\Live\PublishAdminEvents;
use Hydra\Admin\Notifications\NotificationController;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Admin\Updates\HttpReleaseFeed;
use Hydra\Admin\Updates\UpdateCheck;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Versions;
use Hydra\Event\ListenerProvider;
use Hydra\Filesystem\Contracts\PublicStorageInterface;
use Hydra\Filesystem\Disks;
use Hydra\Http\Responder;
use Hydra\Http\Router;
use Hydra\Validation\Validator;
use Hydra\View\Contracts\ViewInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the admin services and, at boot, loads the module screens into the
 * router as ordinary routes.
 */
final class AdminServiceProvider extends ServiceProvider
{
    /**
     * @param list<class-string<ModuleInterface>> $modules
     * @param list<class-string> $middleware
     * @param list<class-string<FileHolderInterface>> $fileHolders what holds file keys outside the modules: a
     *        file one of these leaves out is one the Files module calls an orphan
     */
    public function __construct(
        private readonly array $modules,
        private readonly string $prefix = '/admin',
        private readonly array $middleware = [],
        private readonly array $fileHolders = [],
    ) {}

    /**
     * The templates this package ships. Hand it to the view as a fallback and
     * the admin renders with no templates of your own; put a file of the same
     * name in the application's views and that one is used instead.
     */
    public static function views(): string
    {
        return dirname(__DIR__) . '/views';
    }

    /**
     * The stylesheet and script the shipped templates depend on, served by
     * {@see AssetController} at "{prefix}/assets/{name}". They live here for
     * the same reason the templates do: a field type the package adds arrives
     * styled, in every application, without anyone copying a file.
     */
    public static function assets(): string
    {
        return dirname(__DIR__) . '/assets';
    }

    public function register(ContainerInterface $container): void
    {
        // Live when a broadcaster is bound. Asked by name, so an application
        // without hydrakit/broadcast never loads a class of it.
        $container->singleton(LiveAdmin::class, fn () => new LiveAdmin($container->bound(BroadcasterInterface::class)));

        // Always bound, so a writer outside the admin can publish its changes
        // without asking whether the admin is live.
        $container->singleton(ModuleChanges::class, fn () => new ModuleChanges(
            $container->bound(BroadcasterInterface::class) ? $container->get(BroadcasterInterface::class) : null,
        ));

        // The bell's side. Built only when asked for, and asked for only
        // where the application binds a notification store: without one
        // there are no routes to reach these.
        $container->singleton(Notifier::class, fn () => new Notifier(
            $container->get(NotificationStoreInterface::class),
            $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
            $container->bound(BroadcasterInterface::class) ? $container->get(BroadcasterInterface::class) : null,
        ));

        $container->singleton(NotificationController::class, fn () => new NotificationController(
            $container->get(NotificationStoreInterface::class),
            $container->get(Notifier::class),
            $container->get(GuardInterface::class),
            $container->get(Renderer::class),
            $container->get(Responder::class),
            $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
            $container->get(LiveAdmin::class),
            $this->prefix,
        ));

        $container->singleton(ModuleRegistry::class, function () use ($container) {
            return new ModuleRegistry($container, $this->modules, $this->prefix);
        });

        $container->singleton(Navigation::class, function () use ($container) {
            return new Navigation(
                $container->get(ModuleRegistry::class),
                $container->get(GateInterface::class),
            );
        });

        $container->singleton(Chrome::class, function () use ($container) {
            return new Chrome(
                $container->get(ModuleRegistry::class),
                $container->get(Navigation::class),
            );
        });

        $container->singleton(Uploads::class, function () use ($container) {
            return new Uploads(
                $container->get(Disks::class),
                $container->bound(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        });

        $container->singleton(FileReferences::class, function () use ($container) {
            // Asked of the interface, as the controller's uploads are: see there.
            if (!$container->bound(PublicStorageInterface::class)) {
                throw new LogicException(
                    'File references read the disks, and none are registered. '
                    . 'Register Hydra\\Filesystem\\FilesystemServiceProvider ahead of the AdminServiceProvider.',
                );
            }

            $holders = [];

            foreach ($this->fileHolders as $class) {
                $holder = $container->get($class);

                if (!$holder instanceof FileHolderInterface) {
                    throw new LogicException(sprintf(
                        '%s must implement %s to be listed in the AdminServiceProvider\'s fileHolders.',
                        $class,
                        FileHolderInterface::class,
                    ));
                }

                $holders[] = $holder;
            }

            return new FileReferences(
                $container->get(ModuleRegistry::class),
                $container->get(Disks::class),
                $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
                $holders,
            );
        });

        $container->singleton(Renderer::class, function () use ($container) {
            return new Renderer(
                $container->get(Responder::class),
                $container->get(ViewInterface::class),
            );
        });

        // Registered rather than left to autowiring, and the reason is sharp
        // enough to be worth writing down: PHP-DI skips optional constructor
        // parameters entirely, so a dispatcher appended to the controller's
        // signature with a default would be null in every application,
        // including the ones that had bound one. The admin would announce
        // nothing and nothing would say why. Naming the arguments here is what
        // makes the event system actually reach the controller.
        $container->singleton(AdminController::class, function () use ($container) {
            // The dispatcher is OPTIONAL, the same way auth's is: the admin
            // depends on psr/event-dispatcher and never on hydrakit/event, so an
            // application with no dispatcher bound gets an admin that simply
            // emits no events.
            $events = $container->bound(EventDispatcherInterface::class)
                ? $container->get(EventDispatcherInterface::class)
                : null;

            return new AdminController(
                $container->get(ModuleRegistry::class),
                $container->get(Chrome::class),
                $container->get(Renderer::class),
                $container->get(GateInterface::class),
                $container->get(Responder::class),
                $container->get(Validator::class),
                $events,
                $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock,
                // Optional on the same terms, and with the same trap: an
                // application that binds a reader's zone and gets UTC anyway
                // would show every stored instant an hour or six out with
                // nothing on screen admitting it.
                $container->bound(TimezoneInterface::class)
                    ? $container->get(TimezoneInterface::class)
                    : new FixedTimezone,
                // Only once FilesystemServiceProvider has registered the disks,
                // so an application with no file controls need not register it.
                // Asked of the interface, not of Disks: an autowiring container
                // calls any concrete class bound, and would build Disks from
                // storage interfaces nobody bound.
                $container->bound(PublicStorageInterface::class) ? $container->get(Uploads::class) : null,
                $container->get(LiveAdmin::class),
            );
        });

        $container->singleton(UpdateCheck::class, function () use ($container) {
            return new UpdateCheck(
                new HttpReleaseFeed,
                $container->get(StoreInterface::class),
                $container->get(Versions::class)->hydra(),
                $container->get(Environment::class)->bool('HYDRA_UPDATE_CHECK', true),
            );
        });

        $container->singleton(AssetController::class, function () use ($container) {
            return new AssetController($container->get(Responder::class));
        });

        $container->singleton(FileController::class, function () use ($container) {
            return new FileController(
                $container->get(ResponseFactoryInterface::class),
                $container->bound(PublicStorageInterface::class) ? $container->get(Uploads::class) : null,
            );
        });
    }

    public function boot(ContainerInterface $container): void
    {
        $container->get(Router::class)->loadRoutes($this->routes($container));

        if ($container->get(LiveAdmin::class)->enabled) {
            $this->goLive($container);
        }
    }

    /**
     * Writes are published on their module's topic, and the topic is granted
     * to exactly who may open the module. Each half needs its own piece of
     * the application: a listener provider to hear the writes, a topic policy
     * to grant the topics. Either may be missing, and then that half is.
     */
    private function goLive(ContainerInterface $container): void
    {
        if ($container->bound(ListenerProvider::class)) {
            // Resolved when a write happens, not at boot: a request that
            // writes nothing never builds the broadcaster.
            $container->get(ListenerProvider::class)->listen(
                AdminEvent::class,
                static fn (AdminEvent $event) => (new PublishAdminEvents($container->get(ModuleChanges::class)))($event),
            );
        }

        if ($container->bound(TopicPolicy::class)) {
            // The gate answers for whoever is signed in, and the only request
            // that asks the policy is the one minting that user's token.
            $container->get(TopicPolicy::class)->allow(
                LiveAdmin::topic('{slug}'),
                static function (int|string $userId, array $params) use ($container): bool {
                    $blueprint = $container->get(ModuleRegistry::class)->find($params['slug']);

                    return $blueprint !== null
                        && ($blueprint->ability === null || $container->get(GateInterface::class)->allows($blueprint->ability));
                },
            );

            // A user's own notices: theirs to hear, and nobody else's.
            $container->get(TopicPolicy::class)->allow(
                Notifier::topic('{id}'),
                static fn (int|string $userId, array $params): bool => (string) $userId === $params['id'],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public function routes(ContainerInterface $container): array
    {
        return [
            ...$this->assetRoutes(),
            ...$this->fileRoutes(),
            ...($container->bound(NotificationStoreInterface::class) ? $this->notificationRoutes() : []),
            ...(new ModuleScanner)->scan(
                $container->get(ModuleRegistry::class)->all(),
                $this->prefix,
                $this->middleware,
            ),
        ];
    }

    /**
     * Ahead of the module routes, because the router takes the first match and
     * a module is free to call itself "assets". Deliberately without the
     * admin's middleware: a stylesheet is not a screen, and a sign-in page
     * that has to be styled before anyone has signed in cannot reach one that
     * is behind the gate.
     *
     * @return list<array<string, mixed>>
     */
    private function assetRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => rtrim($this->prefix, '/') . '/assets/{asset}',
            'handler' => [AssetController::class, 'show'],
            'middleware' => [],
            'name' => 'admin.asset',
        ]];
    }

    /**
     * The bell's requests, behind the admin's guard and ahead of the modules,
     * as the file route is. read-all and clear are registered before
     * {id}/read, which would otherwise take them.
     *
     * @return list<array<string, mixed>>
     */
    private function notificationRoutes(): array
    {
        $base = rtrim($this->prefix, '/') . '/notifications';

        return array_map(fn (array $route): array => [
            'method' => $route[0],
            'path' => $base . $route[1],
            'handler' => [NotificationController::class, $route[2]],
            'middleware' => $this->middleware,
            'name' => 'admin.notifications.' . $route[2],
        ], [
            ['GET', '/badge', 'badge'],
            ['GET', '', 'list'],
            ['POST', '/read-all', 'readAll'],
            ['POST', '/clear', 'clear'],
            ['POST', '/{id}/read', 'read'],
        ]);
    }

    /**
     * Private files, behind the same middleware as every screen: signed in is
     * the whole of the check, the way it is for the dashboard. Ahead of the
     * modules for the reason the assets are.
     *
     * @return list<array<string, mixed>>
     */
    private function fileRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => rtrim($this->prefix, '/') . FileUrls::PATH,
            'handler' => [FileController::class, 'show'],
            'middleware' => $this->middleware,
            'name' => 'admin.file',
        ]];
    }
}
