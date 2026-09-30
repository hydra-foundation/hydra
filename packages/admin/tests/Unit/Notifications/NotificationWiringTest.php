<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Notifications;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Notifications\NotificationController;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Notifications\Notifier;
use Hydra\Admin\Testing\ArrayNotificationStore;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Event\ListenerProvider;
use Hydra\Http\CspNonce;
use Hydra\Http\Responder;
use Hydra\Http\Router;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bell's routes exist only where the application keeps notices, sit
 * behind the admin's guard ahead of the modules, and a user's topic is theirs
 * alone.
 */
#[CoversClass(AdminServiceProvider::class)]
final class NotificationWiringTest extends TestCase
{
    public function test_with_a_store_the_four_routes_are_behind_the_guard_and_ahead_of_the_modules(): void
    {
        $container = $this->container(store: true);
        $provider = new AdminServiceProvider([UsersModule::class], '/admin', ['RequireSignIn']);
        $provider->register($container);

        $routes = $provider->routes($container);
        $mine = array_values(array_filter($routes, static fn (array $r): bool => ($r['handler'][0] ?? null) === NotificationController::class));

        $this->assertSame(
            [
                ['GET', '/admin/notifications/badge', 'badge'],
                ['GET', '/admin/notifications', 'list'],
                ['POST', '/admin/notifications/read-all', 'readAll'],
                ['POST', '/admin/notifications/{id}/read', 'read'],
            ],
            array_map(static fn (array $r): array => [$r['method'], $r['path'], $r['handler'][1]], $mine),
        );

        foreach ($mine as $route) {
            $this->assertSame(['RequireSignIn'], $route['middleware']);
            $this->assertLessThan(
                array_search(array_values(array_filter($routes, static fn (array $r): bool => str_starts_with($r['path'], '/admin/users')))[0], $routes, true),
                array_search($route, $routes, true),
            );
        }

        $this->assertInstanceOf(NotificationController::class, $container->get(NotificationController::class));
        $this->assertInstanceOf(Notifier::class, $container->get(Notifier::class));
    }

    public function test_without_a_store_there_are_no_notification_routes(): void
    {
        $container = $this->container(store: false);
        $provider = new AdminServiceProvider([UsersModule::class]);
        $provider->register($container);

        foreach ($provider->routes($container) as $route) {
            $this->assertStringNotContainsString('/notifications', $route['path']);
        }
    }

    public function test_a_users_topic_is_theirs_alone_while_live(): void
    {
        $policy = $this->boot(live: true)->get(TopicPolicy::class);

        $this->assertTrue($policy->permits(7, 'user.7'));
        $this->assertTrue($policy->permits('7', 'user.7'));
        $this->assertFalse($policy->permits(8, 'user.7'));
    }

    public function test_no_user_topic_is_granted_when_not_live(): void
    {
        $this->assertFalse($this->boot(live: false)->get(TopicPolicy::class)->permits(7, 'user.7'));
    }

    private function boot(bool $live): FakeContainer
    {
        $container = $this->container(store: true);
        $container->instance(TopicPolicy::class, new TopicPolicy);
        $container->instance(ListenerProvider::class, new ListenerProvider);

        if ($live) {
            $container->instance(BroadcasterInterface::class, new FakeBroadcaster);
        }

        $container->instance(Router::class, new Router($container));
        $provider = new AdminServiceProvider([UsersModule::class]);
        $provider->register($container);
        $provider->boot($container);

        return $container;
    }

    private function container(bool $store): FakeContainer
    {
        $psr17 = new Psr17Factory;

        return new FakeContainer([
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
            GateInterface::class => new AdminsOnlyGate(true),
            GuardInterface::class => FakeGuard::signedInAs(new FakeUser(7)),
            Responder::class => new Responder($psr17, $psr17),
            ViewInterface::class => new PhpView(AdminServiceProvider::views(), new CspNonce),
            ...($store ? [NotificationStoreInterface::class => new ArrayNotificationStore] : []),
        ]);
    }
}
