<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * A module's create screen wrote a row. Announced after the source took it, so
 * the id is the one the source assigned and a refusal announces nothing.
 */
final class RowCreated extends AdminEvent
{
    /** @param array<string, mixed> $values what was written, as the source took it */
    public function __construct(
        string $module,
        public readonly string $id,
        public readonly array $values,
    ) {
        parent::__construct($module);
    }

    public function action(): string
    {
        return 'admin.row_created';
    }

    public function context(): array
    {
        return [
            'module' => $this->module,
            'id' => $this->id,
            'fields' => array_keys($this->values),
        ];
    }
}
