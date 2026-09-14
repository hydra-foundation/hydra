<?php

declare(strict_types=1);

namespace Hydra\Event\Tests\Unit;

use Hydra\Event\Dispatcher;
use Hydra\Event\EventServiceProvider;
use Hydra\Event\ListenerProvider;
use Hydra\Event\Tests\Support\TestContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use stdClass;

/**
 * The wiring, which is where an event system most plausibly ends up doing
 * nothing: every piece can be correct while the listeners an application
 * registered went into a provider the dispatcher never reads.
 */
#[CoversClass(EventServiceProvider::class)]
final class EventServiceProviderTest extends TestCase
{
    public function test_the_concrete_provider_and_the_psr_interface_are_one_object(): void
    {
        // listen() is not on the PSR interface, so an application registers
        // through the class-string. Two instances would mean every listener it
        // registered belongs to a provider nothing dispatches from.
        $container = $this->register();

        $this->assertSame(
            $container->get(ListenerProvider::class),
            $container->get(ListenerProviderInterface::class),
        );
    }

    public function test_a_listener_registered_after_boot_still_fires(): void
    {
        $container = $this->register();

        $seen = [];
        $container->get(ListenerProvider::class)->listen(
            stdClass::class,
            function (stdClass $event) use (&$seen): void {
                $seen[] = $event;
            },
        );

        $event = new stdClass;
        $container->get(EventDispatcherInterface::class)->dispatch($event);

        $this->assertSame([$event], $seen);
    }

    public function test_the_dispatcher_is_bound_behind_the_psr_interface(): void
    {
        $container = $this->register();

        $this->assertInstanceOf(Dispatcher::class, $container->get(EventDispatcherInterface::class));
        $this->assertSame(
            $container->get(EventDispatcherInterface::class),
            $container->get(EventDispatcherInterface::class),
        );
    }

    public function test_registering_binds_nothing_until_it_is_asked_for(): void
    {
        // Boot cost, and the reason every binding here is a closure: an
        // application that dispatches no events should build no dispatcher.
        $container = $this->register();

        $this->assertFalse($container->isResolved(ListenerProvider::class));
        $this->assertFalse($container->isResolved(EventDispatcherInterface::class));
    }

    private function register(): TestContainer
    {
        $container = new TestContainer;
        (new EventServiceProvider)->register($container);

        return $container;
    }
}
