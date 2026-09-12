<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Closure;
use Hydra\View\HtmlView;
use LogicException;

/**
 * One column of a module, declared once and projected onto every surface that
 * wants it: a table cell, a detail row, an export column. Display rules are
 * declared per surface.
 */
final class Field
{
    private string $label;

    /** @var list<Surface> */
    private array $surfaces = [Surface::List, Surface::Show];

    private bool $sortable = false;
    private bool $searchable = false;
    private bool $filterable = false;

    /** @var array<string, array{fn: Closure, decorates: bool}> keyed by Surface->value */
    private array $formatters = [];

    /** @var array<string, string> keyed by Surface->value */
    private array $placeholders = [];

    /** @param array<array-key, string>|null $options */
    private function __construct(
        private readonly string $name,
        private readonly FieldType $type,
        private readonly ?array $options = null,
    ) {
        $this->label = ucfirst(str_replace('_', ' ', $name));
    }

    public static function id(string $name = 'id'): self
    {
        return new self($name, FieldType::Id);
    }

    public static function text(string $name): self
    {
        return new self($name, FieldType::Text);
    }

    /**
     * Keys are stored column values. PHP turns a numeric-looking one into an
     * int, so a status map keyed '200' arrives here keyed 200.
     *
     * @param array<array-key, string> $options
     */
    public static function select(string $name, array $options): self
    {
        return new self($name, FieldType::Select, $options);
    }

    public static function datetime(string $name): self
    {
        return new self($name, FieldType::DateTime);
    }

    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    public function sortable(bool $sortable = true): self
    {
        $clone = clone $this;
        $clone->sortable = $sortable;

        return $clone;
    }

    public function searchable(bool $searchable = true): self
    {
        $clone = clone $this;
        $clone->searchable = $searchable;

        return $clone;
    }

    public function filterable(bool $filterable = true): self
    {
        $clone = clone $this;
        $clone->filterable = $filterable;

        return $clone;
    }

    public function onlyOn(Surface ...$surfaces): self
    {
        $clone = clone $this;
        $clone->surfaces = array_values($surfaces);

        return $clone;
    }

    public function hiddenOn(Surface ...$surfaces): self
    {
        $clone = clone $this;
        $clone->surfaces = array_values(array_filter(
            $this->surfaces,
            static fn (Surface $surface): bool => !in_array($surface, $surfaces, true),
        ));

        return $clone;
    }

    /**
     * Replace the value for display, on every surface unless some are named.
     * Rejected by {@see Definition::compile()} on a searchable() field.
     *
     * @param Closure(mixed, array<string, mixed>): (string|HtmlView) $formatter
     */
    public function format(Closure $formatter, Surface ...$on): self
    {
        return $this->withFormatter($formatter, false, $on);
    }

    /**
     * format() for a formatter that leaves the stored value findable in its
     * output, so the field may stay searchable().
     *
     * @param Closure(mixed, array<string, mixed>): (string|HtmlView) $formatter
     */
    public function decorate(Closure $formatter, Surface ...$on): self
    {
        return $this->withFormatter($formatter, true, $on);
    }

    /** What to show in place of a null or empty value. Not itself searchable. */
    public function emptyAs(string $placeholder, Surface ...$on): self
    {
        $clone = clone $this;

        foreach ($this->surfacesFor($on) as $surface) {
            $clone->placeholders[$surface->value] = $placeholder;
        }

        return $clone;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): FieldType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<array-key, string>|null */
    public function options(): ?array
    {
        return $this->options;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function appearsOn(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    public function rewritesValueOn(Surface $surface): bool
    {
        return ($this->formatters[$surface->value] ?? null) !== null
            && !$this->formatters[$surface->value]['decorates'];
    }

    /** @param array<string, mixed> $row */
    public function display(Surface $surface, array $row): string|HtmlView
    {
        $value = $row[$this->name] ?? null;
        $placeholder = $this->placeholders[$surface->value] ?? null;

        if ($placeholder !== null && ($value === null || $value === '')) {
            return $placeholder;
        }

        $formatter = $this->formatters[$surface->value]['fn'] ?? null;

        if ($formatter !== null) {
            return $this->formatted($formatter($value, $row));
        }

        if ($this->options !== null && is_scalar($value)) {
            return $this->options[(string) $value] ?? (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param list<Surface> $on */
    private function withFormatter(Closure $formatter, bool $decorates, array $on): self
    {
        $clone = clone $this;

        foreach ($this->surfacesFor($on) as $surface) {
            $clone->formatters[$surface->value] = ['fn' => $formatter, 'decorates' => $decorates];
        }

        return $clone;
    }

    /**
     * @param list<Surface> $on
     * @return list<Surface>
     */
    private function surfacesFor(array $on): array
    {
        return $on === [] ? Surface::cases() : array_values($on);
    }

    private function formatted(mixed $formatted): string|HtmlView
    {
        if (is_string($formatted) || $formatted instanceof HtmlView) {
            return $formatted;
        }

        throw new LogicException(sprintf(
            'The formatter for admin field "%s" returned %s; it must return a string or %s.',
            $this->name,
            get_debug_type($formatted),
            HtmlView::class,
        ));
    }
}
