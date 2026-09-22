<?php

declare(strict_types=1);

namespace Hydra\Event\Testing;

use Closure;
use PHPUnit\Framework\Assert;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A dispatcher that keeps every event it is handed and delivers none, for
 * asserting on.
 *
 * What a test of the dispatching side wants to know is that the right thing
 * was announced, not what some listener did about it. The shipped
 * {@see \Hydra\Event\Dispatcher} needs a listener provider wired before it
 * does anything observable; this needs nothing, and cannot silently record
 * nothing because a provider was left out.
 */
final class FakeDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    private array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * Every event dispatched, oldest first, or only those of $type.
     *
     * @template T of object
     * @param class-string<T>|null $type
     * @return ($type is null ? list<object> : list<T>)
     */
    public function dispatched(?string $type = null): array
    {
        return $type === null
            ? $this->events
            : array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof $type));
    }

    /** @return list<class-string> */
    public function types(): array
    {
        return array_map(get_class(...), $this->events);
    }

    /**
     * The first event of $type, failing the test when there is none.
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function first(string $type): object
    {
        $event = $this->dispatched($type)[0] ?? null;

        Assert::assertNotNull($event, "No {$type} was dispatched.");

        return $event;
    }

    /** Forget everything dispatched so far, so a test can assert on what follows a setup step. */
    public function reset(): void
    {
        $this->events = [];
    }

    /**
     * An event of $type was dispatched, and one for which $where returns true
     * when it is given.
     *
     * @param class-string $type
     * @param (Closure(object): bool)|null $where
     */
    public function assertDispatched(string $type, ?Closure $where = null): void
    {
        $matching = array_filter(
            $this->dispatched($type),
            static fn (object $event): bool => $where === null || $where($event),
        );

        $message = $where === null
            ? "No {$type} was dispatched."
            : "No {$type} matching the condition was dispatched.";

        Assert::assertNotEmpty($matching, $message);
    }

    /** @param class-string $type */
    public function assertNotDispatched(string $type): void
    {
        $count = count($this->dispatched($type));

        Assert::assertSame(0, $count, "{$type} was dispatched {$count} times.");
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertSame([], $this->types(), count($this->events) . ' events were dispatched.');
    }
}
