<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * A module's action ran to the end. Like a delete, announced only once it has:
 * an action that refused did nothing worth a line.
 */
final class ActionTaken extends AdminEvent
{
    /** @param string|null $id the row it ran on, or null for a module action */
    public function __construct(
        string $module,
        public readonly string $name,
        public readonly ?string $id = null,
    ) {
        parent::__construct($module);
    }

    public function action(): string
    {
        return 'admin.action';
    }

    public function context(): array
    {
        return ['module' => $this->module, 'action' => $this->name, 'id' => $this->id];
    }
}
