<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ListScreen;
use Hydra\Admin\Sources\CallableSource;
use LogicException;

/**
 * The fluent half of a module: write-only, immutable, and compiled to a
 * Blueprint before anything else is allowed to read it.
 */
final class Definition
{
    private string $title;
    private ?string $icon = null;
    private ?string $group = null;
    private ?string $ability = null;
    private SourceInterface|string|null $source = null;

    /** @var list<Field> */
    private array $fields = [];

    /** @var list<ScreenInterface> */
    private array $screens = [];

    private int $perPage = 25;
    private ?string $defaultSort = null;
    private string $defaultDirection = 'asc';

    private function __construct(private readonly string $slug)
    {
        $this->title = ucfirst(str_replace(['-', '_'], ' ', $slug));
    }

    public static function make(string $slug): self
    {
        return new self($slug);
    }

    public function title(string $title): self
    {
        $clone = clone $this;
        $clone->title = $title;

        return $clone;
    }

    public function icon(string $icon): self
    {
        $clone = clone $this;
        $clone->icon = $icon;

        return $clone;
    }

    /**
     * The heading this module sits under in the sidebar. Purely how the menu is
     * organised — modules in a group need not relate to each other, and nothing
     * about a group is addressable, so it is a label and not a screen.
     */
    public function group(string $group): self
    {
        $clone = clone $this;
        $clone->group = $group;

        return $clone;
    }

    /** @param class-string|null $ability */
    public function ability(?string $ability): self
    {
        $clone = clone $this;
        $clone->ability = $ability;

        return $clone;
    }

    /**
     * A service id is resolved from the container per request, so declaring a
     * source never opens a connection at boot; an instance or callable is used
     * as given.
     */
    public function source(SourceInterface|callable|string $source): self
    {
        $clone = clone $this;
        $clone->source = match (true) {
            $source instanceof SourceInterface, is_string($source) => $source,
            default => new CallableSource($source),
        };

        return $clone;
    }

    public function fields(Field ...$fields): self
    {
        $clone = clone $this;
        $clone->fields = [...$this->fields, ...array_values($fields)];

        return $clone;
    }

    public function perPage(int $perPage): self
    {
        $clone = clone $this;
        $clone->perPage = max(1, $perPage);

        return $clone;
    }

    public function defaultSort(string $field, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->defaultSort = $field;
        $clone->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $clone;
    }

    public function screens(ScreenInterface ...$screens): self
    {
        $clone = clone $this;
        $clone->screens = [...$this->screens, ...array_values($screens)];

        return $clone;
    }

    public function compile(): Blueprint
    {
        $this->assertSearchableFieldsAreFindable();
        $this->assertRowScreensHaveSomethingToName();

        $screens = $this->screens;

        if ($this->source !== null && $this->fields !== [] && $this->screenNamed('list') === null) {
            $screens = [new ListScreen, ...$screens];
        }

        if ($screens === []) {
            throw new LogicException("Admin module \"{$this->slug}\" declares no screens.");
        }

        $seen = [];

        foreach ($screens as $screen) {
            if ($screen instanceof FormScreen && $screen->controls() === []) {
                throw new LogicException(
                    "Admin module \"{$this->slug}\" declares a form screen \"{$screen->name()}\" with no inputs."
                );
            }

            $route = $screen->method() . ' ' . trim($screen->path(), '/');

            if (isset($seen[$route])) {
                throw new LogicException(
                    "Admin module \"{$this->slug}\" declares two screens at \"{$route}\"."
                );
            }

            $seen[$route] = true;
        }

        return new Blueprint(
            slug: $this->slug,
            title: $this->title,
            icon: $this->icon,
            group: $this->group,
            ability: $this->ability,
            source: $this->source,
            fields: $this->fields,
            screens: $screens,
            perPage: $this->perPage,
            defaultSort: $this->defaultSort,
            defaultDirection: $this->defaultDirection,
        );
    }

    private function assertSearchableFieldsAreFindable(): void
    {
        foreach ($this->fields as $field) {
            if (!$field->isSearchable() || !$field->rewritesValueOn(Surface::List)) {
                continue;
            }

            throw new LogicException(sprintf(
                'Admin module "%s" declares field "%s" as both searchable() and format(): '
                . 'the search box would never find what the cell shows. Use decorate() when the '
                . 'stored value survives into the output, or emptyAs() for a null placeholder.',
                $this->slug,
                $field->name(),
            ));
        }
    }

    /**
     * A screen whose path carries {id} is handed one from the request and hands it
     * to the source. The table has to be able to put one there in the first place,
     * and Field::id() is what says which column that is — without it the row links
     * simply never render, which is a hard thing to notice and a harder one to
     * explain.
     */
    private function assertRowScreensHaveSomethingToName(): void
    {
        $identified = false;

        foreach ($this->fields as $field) {
            $identified = $identified || $field->type() === FieldType::Id;
        }

        if ($identified) {
            return;
        }

        foreach ($this->screens as $screen) {
            if (!str_contains($screen->path(), '{id}')) {
                continue;
            }

            throw new LogicException(sprintf(
                'Admin module "%s" declares a screen at "%s" that answers for one row, '
                . 'but no Field::id() to name one with.',
                $this->slug,
                $screen->path(),
            ));
        }
    }

    private function screenNamed(string $name): ?ScreenInterface
    {
        foreach ($this->screens as $screen) {
            if ($screen->name() === $name) {
                return $screen;
            }
        }

        return null;
    }
}
