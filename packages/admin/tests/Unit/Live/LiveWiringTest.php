<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Live;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Live\LiveAdmin;
use Hydra\Admin\Live\ModuleChanges;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DocumentsModule;
use Hydra\Admin\Tests\Support\UsersModule;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Event\ListenerProvider;
use Hydra\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The admin goes live on its own when a broadcaster is bound: it publishes
 * its writes, grants each module's topic to whoever may open the module, and
 * tells its views to listen. Without one, nothing changes.
 */
#[CoversClass(AdminServiceProvider::class)]
#[CoversClass(LiveAdmin::class)]
final class LiveWiringTest extends TestCase
{
    public function test_with_a_broadcaster_admin_writes_are_published(): void
    {
        $container = $this->boot(true, gate: true);

        foreach ($container->get(ListenerProvider::class)->getListenersForEvent(new RowCreated('users', '7', [])) as $listener) {
            $listener(new RowCreated('users', '7', []));
        }

        $container->get(FakeBroadcaster::class)->assertPublished('module.users', 'changed', times: 1);
        $this->assertTrue($container->get(LiveAdmin::class)->enabled);
    }

    public function test_a_module_with_no_ability_is_open_to_anyone_signed_in(): void
    {
        $this->assertTrue($this->boot(true, gate: false)->get(TopicPolicy::class)->permits(1, 'module.documents'));
    }

    public function test_a_module_with_an_ability_is_open_only_to_whom_the_gate_allows(): void
    {
        $this->assertTrue($this->boot(true, gate: true)->get(TopicPolicy::class)->permits(1, 'module.users'));
        $this->assertFalse($this->boot(true, gate: false)->get(TopicPolicy::class)->permits(1, 'module.users'));
    }

    public function test_an_unknown_module_is_refused(): void
    {
        $this->assertFalse($this->boot(true, gate: true)->get(TopicPolicy::class)->permits(1, 'module.nothing'));
    }

    public function test_without_a_broadcaster_nothing_is_wired(): void
    {
        $container = $this->boot(false, gate: true);

        $this->assertFalse($container->get(LiveAdmin::class)->enabled);
        $this->assertSame([], iterator_to_array($container->get(ListenerProvider::class)->getListenersForEvent(new RowCreated('users', '7', [])), false));
        $this->assertFalse($container->get(TopicPolicy::class)->permits(1, 'module.documents'));
    }

    public function test_a_broadcaster_alone_is_enough_to_go_live(): void
    {
        // An app that binds a broadcaster but no dispatcher and no topic
        // policy still gets the flag, and nothing throws.
        $container = new FakeContainer([
            BroadcasterInterface::class => new FakeBroadcaster,
            GateInterface::class => new AdminsOnlyGate(true),
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
        ]);
        $container->instance(Router::class, new Router($container));
        $provider = new AdminServiceProvider([UsersModule::class]);
        $provider->register($container);
        $provider->boot($container);

        $this->assertTrue($container->get(LiveAdmin::class)->enabled);
    }

    public function test_module_changes_publish_through_the_bound_broadcaster(): void
    {
        $container = $this->boot(true, gate: true);

        $container->get(ModuleChanges::class)->publish('users', 7);

        $container->get(FakeBroadcaster::class)->assertPublished('module.users', 'changed', times: 1);
    }

    public function test_module_changes_resolve_without_a_broadcaster_and_do_nothing(): void
    {
        $container = $this->boot(false, gate: true);

        $container->get(ModuleChanges::class)->publish('users', 7);

        $container->get(FakeBroadcaster::class)->assertNothingPublished();
    }

    private function boot(bool $broadcasting, bool $gate): FakeContainer
    {
        $broadcaster = new FakeBroadcaster;
        $container = new FakeContainer([
            ListenerProvider::class => new ListenerProvider,
            TopicPolicy::class => new TopicPolicy,
            GateInterface::class => new AdminsOnlyGate($gate),
            UsersModule::class => new UsersModule,
            DocumentsModule::class => new DocumentsModule,
            ArraySource::class => new ArraySource,
            FakeBroadcaster::class => $broadcaster,
        ]);
        $container->instance(Router::class, new Router($container));

        if ($broadcasting) {
            $container->instance(BroadcasterInterface::class, $broadcaster);
        }

        $provider = new AdminServiceProvider([UsersModule::class, DocumentsModule::class]);
        $provider->register($container);
        $provider->boot($container);

        return $container;
    }
}
