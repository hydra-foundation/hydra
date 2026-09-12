<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SourceInterface;

/**
 * The compiled, read-only form of a Definition: the receipt. Routes, navigation,
 * criteria whitelisting and `admin:routes` all read this and nothing else.
 */
final readonly class Blueprint
{
    /**
     * @param list<Field> $fields
     * @param list<ScreenInterface> $screens
     */
    public function __construct(
        public string $slug,
        public string $title,
        public ?string $icon,
        public ?string $group,
        public ?string $ability,
        public SourceInterface|string|null $source,
        public array $fields,
        public array $screens,
        public int $perPage,
        public ?string $defaultSort,
        public string $defaultDirection,
    ) {}

    /** @return list<Field> */
    public function fieldsOn(Surface $surface): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (Field $field): bool => $field->appearsOn($surface),
        ));
    }

    /** @return list<Field> */
    public function sortable(): array
    {
        return $this->where(static fn (Field $field): bool => $field->isSortable());
    }

    /** @return list<Field> */
    public function searchable(): array
    {
        return $this->where(static fn (Field $field): bool => $field->isSearchable());
    }

    /** @return list<Field> */
    public function filterable(): array
    {
        return $this->where(static fn (Field $field): bool => $field->isFilterable());
    }

    /** The column that names a row, from the first Field::id() declared. */
    public function identifier(): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->type() === FieldType::Id) {
                return $field->name();
            }
        }

        return null;
    }

    public function screen(string $name): ?ScreenInterface
    {
        foreach ($this->screens as $screen) {
            if ($screen->name() === $name) {
                return $screen;
            }
        }

        return null;
    }

    /** @param callable(Field): bool $matches @return list<Field> */
    private function where(callable $matches): array
    {
        return array_values(array_filter($this->fields, $matches));
    }
}
