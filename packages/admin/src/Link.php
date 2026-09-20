<?php

declare(strict_types=1);

namespace Hydra\Admin;

use LogicException;

/**
 * A named view of a list, declared by the module and reached in one click:
 * "Open", "Suspended", "Failed". It is a preset over the filters the source
 * already answers, not a filter of its own, so every existing source supports
 * one without knowing links exist.
 *
 * The key and not the label is what the URL carries, because a link is a place
 * a visitor bookmarks and a label is a word somebody rewrites.
 */
final class Link
{
    /** @var array<string, string> */
    private array $filters = [];

    private function __construct(
        private readonly string $key,
        private readonly string $label,
    ) {
        if ($key === '') {
            throw new LogicException("Admin link \"{$label}\" has no key to live at.");
        }
    }

    /** The key is the label made URL-safe; keyed() overrides it. */
    public static function make(string $label): self
    {
        return new self(self::slug($label), $label);
    }

    /**
     * A stable key for a label that may be rewritten. Worth spelling out on any
     * link an operator is likely to have bookmarked.
     */
    public function keyed(string $key): self
    {
        $clone = new self($key, $this->label);
        $clone->filters = $this->filters;

        return $clone;
    }

    /**
     * One more column this view pins. The value is matched the way a toolbar
     * filter is, so the source must already name the column filterable — which
     * is what `admin:check` verifies.
     */
    public function where(string $column, string $value): self
    {
        $clone = clone $this;
        $clone->filters[$column] = $value;

        return $clone;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return $this->filters;
    }

    private static function slug(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)) ?? '', '-');
    }
}
