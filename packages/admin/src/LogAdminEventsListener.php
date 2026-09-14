<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Events\Exported;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * An optional listener that writes a PSR-3 line for each admin event: the
 * audit trail of who changed what in the backend, which every application
 * wants and none writes until it is needed.
 *
 * One registration covers everything, present and future, because the listener
 * provider matches an event's subtypes:
 *
 *     $listeners->listen(AdminEvent::class, new LogAdminEventsListener($logger));
 *
 * Not registered by {@see AdminServiceProvider}. Where an application's logs go
 * and what belongs in them is the application's decision, and a package that
 * started writing to one on its own behalf would be making it.
 */
final class LogAdminEventsListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AdminEvent $event): void
    {
        // An export is the one admin action that moves a whole table at once,
        // and it is worth being able to find in a log without already knowing
        // to look for it.
        $this->logger->log(
            $event instanceof Exported ? LogLevel::NOTICE : LogLevel::INFO,
            $event->action(),
            $event->context(),
        );
    }
}
