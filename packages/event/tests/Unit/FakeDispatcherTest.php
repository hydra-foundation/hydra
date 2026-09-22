<?php

declare(strict_types=1);

namespace Hydra\Event\Tests\Unit;

use Hydra\Event\Testing\FakeDispatcher;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeDispatcher::class)]
final class FakeDispatcherTest extends TestCase
{
    public function test_it_hands_the_event_back_and_keeps_it(): void
    {
        $events = new FakeDispatcher;
        $event = new Shipped('A-1');

        $this->assertSame($event, $events->dispatch($event));
        $this->assertSame([$event], $events->dispatched());
    }

    public function test_it_filters_by_type_and_lists_types_in_order(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Shipped('A-1'));
        $events->dispatch(new Cancelled);
        $events->dispatch(new Shipped('A-2'));

        $this->assertSame([Shipped::class, Cancelled::class, Shipped::class], $events->types());
        $this->assertSame(['A-1', 'A-2'], array_map(static fn (Shipped $e): string => $e->order, $events->dispatched(Shipped::class)));
        $this->assertSame('A-1', $events->first(Shipped::class)->order);
    }

    public function test_first_fails_when_nothing_of_that_type_was_dispatched(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No ' . Shipped::class . ' was dispatched.');

        (new FakeDispatcher)->first(Shipped::class);
    }

    public function test_reset_forgets_what_came_before(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Cancelled);
        $events->reset();

        $events->assertNothingDispatched();
    }

    public function test_assert_dispatched_passes_with_and_without_a_condition(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Shipped('A-1'));

        $events->assertDispatched(Shipped::class);
        $events->assertDispatched(Shipped::class, static fn (Shipped $e): bool => $e->order === 'A-1');
        $events->assertNotDispatched(Cancelled::class);
    }

    public function test_assert_dispatched_fails_when_no_event_meets_the_condition(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Shipped('A-1'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No ' . Shipped::class . ' matching the condition was dispatched.');
        $events->assertDispatched(Shipped::class, static fn (Shipped $e): bool => $e->order === 'B-9');
    }

    public function test_assert_dispatched_fails_when_none_was(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No ' . Shipped::class . ' was dispatched.');

        (new FakeDispatcher)->assertDispatched(Shipped::class);
    }

    public function test_assert_not_dispatched_fails_with_the_count(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Cancelled);
        $events->dispatch(new Cancelled);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(Cancelled::class . ' was dispatched 2 times.');
        $events->assertNotDispatched(Cancelled::class);
    }

    public function test_assert_nothing_dispatched_fails_with_the_count(): void
    {
        $events = new FakeDispatcher;
        $events->dispatch(new Cancelled);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('1 events were dispatched.');
        $events->assertNothingDispatched();
    }
}

final class Shipped
{
    public function __construct(public readonly string $order) {}
}

final class Cancelled {}
