<?php

declare(strict_types=1);

namespace Hydra\Admin\Live;

use Hydra\Admin\Events\ActionTaken;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowDeleted;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Broadcast\Contracts\BroadcasterInterface;

/**
 * Tells every open list of a module that something in it changed, so each
 * refetches its own table: `module.{slug}`, event `changed`.
 *
 * Only the action and the row's id travel. A page asks the server again, so
 * sorting, filters, paging and who may see which row stay the server's, and a
 * module's values (whatever the application declared, passwords included)
 * never reach anyone listening.
 *
 * An export changes nothing, so it is not published. Registered by
 * {@see \Hydra\Admin\AdminServiceProvider} when a broadcaster is bound, and
 * best effort like every broadcast: a Redis outage is the broadcaster's to log.
 */
final readonly class PublishAdminEvents
{
    public function __construct(private BroadcasterInterface $broadcaster) {}

    public function __invoke(AdminEvent $event): void
    {
        $id = match (true) {
            $event instanceof RowCreated, $event instanceof RowUpdated, $event instanceof RowDeleted => $event->id,
            $event instanceof ActionTaken => $event->id,
            default => false,
        };

        if ($id === false) {
            return;
        }

        $this->broadcaster->publish("module.{$event->module}", 'changed', ['action' => $event->action(), 'id' => $id]);
    }
}
