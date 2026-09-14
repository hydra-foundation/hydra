<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * A module's delete screen removed a row. Announced only once the source has
 * taken it: a delete the source refused left the row where it was, and an audit
 * trail that recorded the attempt as the deed would be worse than none.
 */
final class RowDeleted extends AdminEvent
{
    public function __construct(
        string $module,
        public readonly string $id,
    ) {
        parent::__construct($module);
    }

    public function action(): string
    {
        return 'admin.row_deleted';
    }

    public function context(): array
    {
        return ['module' => $this->module, 'id' => $this->id];
    }
}
