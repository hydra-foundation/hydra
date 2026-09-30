<?php

declare(strict_types=1);

namespace Hydra\Admin\Live;

use Hydra\Broadcast\Contracts\BroadcasterInterface;

/**
 * Tells a module's open lists that a row changed, for a write the admin did
 * not make itself: a command, a job, a webhook. Each list refetches its own
 * table, so a table is live exactly when every writer of it calls this.
 *
 * Bound whether or not the admin is live, with no broadcaster when it is not,
 * so a writer can depend on it without knowing if hydrakit/broadcast is
 * installed. Best effort like every broadcast: an outage is the
 * broadcaster's to log, and the write has already happened.
 */
final readonly class ModuleChanges
{
    public function __construct(private ?BroadcasterInterface $broadcaster = null) {}

    /**
     * The id only, or null for a change to many rows: a broadcast never
     * carries the row. The payload keeps one shape so a listener can rely on
     * it; the action comes first when there is one.
     */
    public function publish(string $slug, int|string|null $id = null, ?string $action = null): void
    {
        $data = $action === null ? ['id' => $id] : ['action' => $action, 'id' => $id];

        $this->broadcaster?->publish(LiveAdmin::topic($slug), 'changed', $data);
    }
}
