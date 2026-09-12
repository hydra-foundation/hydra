<?php

declare(strict_types=1);

namespace Hydra\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * The mutable half of the event system: where listeners are registered and, at
 * dispatch time, matched to an event.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    /**
     * The listener fires for $eventType and for any subtype of it, so a
     * listener on a base event sees everything derived from it.
     *
     * @param class-string $eventType
     */
    public function listen(string $eventType, callable $listener): void
    {
        $this->listeners[$eventType][] = $listener;
    }

    /**
     * Ordered by when each type was first registered, then by registration
     * within that type.
     *
     * @return iterable<callable>
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
