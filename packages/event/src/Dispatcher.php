<?php

declare(strict_types=1);

namespace Hydra\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Dispatcher
 *
 * The read side of the event system: hand it an event object and it calls every
 * listener the provider matched, in order
 */
final class Dispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerProviderInterface $listeners,
    ) {}

    public function dispatch(object $event): object
    {
        // Only pay for the interface check when the event opts into stopping.
        $stoppable = $event instanceof StoppableEventInterface;

        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
