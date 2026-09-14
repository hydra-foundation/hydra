<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * A module's edit screen rewrote a row. It carries the row as it stood before
 * the write, because "a row changed" is a far less useful thing to have
 * recorded than what changed about it, and afterwards nobody can reconstruct it.
 */
final class RowUpdated extends AdminEvent
{
    /**
     * @param array<string, mixed> $values what was written
     * @param array<string, mixed> $before the row the source held first
     */
    public function __construct(
        string $module,
        public readonly string $id,
        public readonly array $values,
        public readonly array $before,
    ) {
        parent::__construct($module);
    }

    public function action(): string
    {
        return 'admin.row_updated';
    }

    public function context(): array
    {
        return [
            'module' => $this->module,
            'id' => $this->id,
            'changed' => $this->changed(),
        ];
    }

    /**
     * The fields whose value the write actually moved.
     *
     * Compared loosely on purpose: a form submits strings, and a source that
     * stored an int 1 has not had it changed by a submitted "1". A strict
     * comparison would report every field of every form as changed, which is
     * the same as reporting none of them.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        $changed = [];

        foreach ($this->values as $name => $value) {
            if (!array_key_exists($name, $this->before) || $this->before[$name] != $value) {
                $changed[] = $name;
            }
        }

        return $changed;
    }
}
