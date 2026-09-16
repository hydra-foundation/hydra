<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * A module's delete screen removed a row. Announced only once the source has
 * taken it: a delete the source refused left the row where it was, and an audit
 * trail that recorded the attempt as the deed would be worse than none.
 *
 * It carries the row as it stood, for the same reason {@see RowUpdated} carries
 * the one it replaced, only more so: an updated row can at least still be read,
 * and a deleted one is gone. Empty when the module's source reads no single row
 * and so had none to offer, which is a fact about the module rather than a
 * failure — the event is still announced, because the row still went.
 */
final class RowDeleted extends AdminEvent
{
    /** @param array<string, mixed> $before the row the source held, or empty */
    public function __construct(
        string $module,
        public readonly string $id,
        public readonly array $before = [],
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
