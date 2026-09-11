<?php

declare(strict_types=1);

namespace Hydra\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Listener provider
 *
 * The mutable half of the event system: where listeners are registered and, at
 * dispatch time, matched to an event
 */
final class ListenerProvider implements ListenerProviderInterface
{
    private array $listeners = [];

    /**
     * Register a listener for an event type. The type is a class-string; the
     * listener fires for that class and any subtype of it (see class docblock).
     */
    public function listen(string $eventType, callable $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    /**
     * Every listener whose registered type the given event is an instance of,
     * yielded in registration order (and in the order the types were first
     * registered). The dispatcher calls each in turn.
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $eventType => $listeners) {
            if ($event instanceof $eventType) {
                yield from $listeners;
            }
        }
    }
}
