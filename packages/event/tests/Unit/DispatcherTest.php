<?php

declare(strict_types=1);

namespace Hydra\Event\Tests\Unit;

use Hydra\Event\Dispatcher;
use Hydra\Event\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * The dispatcher is driven against the REAL {@see ListenerProvider}, not a mock
 * of it, so these prove the actual match-and-call path end to end.
 */
final class DispatcherTest extends TestCase
{
    private ListenerProvider $listeners;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->listeners = new ListenerProvider;
        $this->dispatcher = new Dispatcher($this->listeners);
    }

    public function test_calls_a_listener_registered_for_the_event(): void
    {
        $seen = [];
        $this->listeners->listen(SampleEvent::class, function (SampleEvent $e) use (&$seen) {
            $seen[] = $e->tag;
        });

        $this->dispatcher->dispatch(new SampleEvent('a'));

        $this->assertSame(['a'], $seen);
    }

    public function test_returns_the_same_event_instance(): void
    {
        $event = new SampleEvent('x');

        $this->assertSame($event, $this->dispatcher->dispatch($event));
    }

    public function test_ignores_listeners_for_other_event_types(): void
    {
        $called = false;
        $this->listeners->listen(OtherEvent::class, function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch(new SampleEvent('a'));

        $this->assertFalse($called);
    }

    public function test_calls_multiple_listeners_in_registration_order(): void
    {
        $order = [];
        $this->listeners->listen(SampleEvent::class, function () use (&$order) { $order[] = 1; });
        $this->listeners->listen(SampleEvent::class, function () use (&$order) { $order[] = 2; });

        $this->dispatcher->dispatch(new SampleEvent('a'));

        $this->assertSame([1, 2], $order);
    }

    public function test_a_listener_can_mutate_the_event_for_later_listeners(): void
    {
        $this->listeners->listen(SampleEvent::class, function (SampleEvent $e) { $e->tag .= '!'; });
        $this->listeners->listen(SampleEvent::class, function (SampleEvent $e) use (&$final) { $final = $e->tag; });

        $event = $this->dispatcher->dispatch(new SampleEvent('a'));

        $this->assertSame('a!', $event->tag);
        $this->assertSame('a!', $final);
    }

    public function test_stoppable_event_halts_the_chain(): void
    {
        $order = [];
        $this->listeners->listen(StoppableSampleEvent::class, function (StoppableSampleEvent $e) use (&$order) {
            $order[] = 1;
            $e->stop();
        });
        $this->listeners->listen(StoppableSampleEvent::class, function () use (&$order) {
            $order[] = 2;
        });

        $this->dispatcher->dispatch(new StoppableSampleEvent);

        // The second listener never runs: propagation stopped after the first.
        $this->assertSame([1], $order);
    }
}

final class SampleEvent
{
    public function __construct(public string $tag) {}
}

final class OtherEvent
{
}

final class StoppableSampleEvent implements StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
