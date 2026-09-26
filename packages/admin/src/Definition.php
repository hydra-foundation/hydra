<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\LinkCountsScreen;
use Hydra\Admin\Screens\ListScreen;
use Hydra\Admin\Screens\WidgetScreen;
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

    /** @var list<Link> */
    private array $links = [];

    private int $perPage = 25;
    private ?string $defaultSort = null;
    private string $defaultDirection = 'asc';
    private ?string $gone = null;

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
     * organised: modules in a group need not relate to each other, and nothing
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

    /**
     * Named views of the list, offered above the table as one-click filters.
     * They pin columns the source already filters on, so the toolbar's own
     * filters still apply on top of whichever link is showing.
     */
    public function links(Link ...$links): self
    {
        $clone = clone $this;
        $clone->links = [...$this->links, ...array_values($links)];

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

    /**
     * What to tell a visitor whose row went away after the list was drawn,
     * shown over the list instead of a bare 404.
     */
    public function gone(string $message): self
    {
        $clone = clone $this;
        $clone->gone = $message;

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
        $this->assertExportHasSomethingToWrite();
        $this->assertLinksHaveSomethingToCount();
        $this->assertWidgetsAreDistinct();

        $screens = $this->screens;

        if ($this->source !== null && $this->fields !== [] && $this->screenNamed('list') === null) {
            $screens = [new ListScreen, ...$screens];
        }

        if ($this->links !== [] && $this->screenNamed('counts') === null) {
            $screens = [...$screens, new LinkCountsScreen];
        }

        $screens = [...$screens, ...$this->widgetRoutes($screens)];
        $this->assertActionsAreRunnable($screens);

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
            links: $this->links,
            perPage: $this->perPage,
            defaultSort: $this->defaultSort,
            defaultDirection: $this->defaultDirection,
            gone: $this->gone,
        );
    }

    /**
     * An action's name is how the controller and the templates find it, so one
     * that shares a name with another screen, declared or added, would find
     * the wrong one.
     *
     * @param list<ScreenInterface> $screens
     */
    private function assertActionsAreRunnable(array $screens): void
    {
        foreach ($screens as $screen) {
            if (!$screen instanceof ActionScreen) {
                continue;
            }

            if ($screen->action() === null) {
                throw new LogicException(
                    "Admin module \"{$this->slug}\" declares an action \"{$screen->name()}\" that runs nothing."
                );
            }

            $named = $screen->name();
            $taken = array_filter(
                $screens,
                static fn (ScreenInterface $other): bool => $other !== $screen && $other->name() === $named,
            );

            if ($taken !== []) {
                throw new LogicException(
                    "Admin module \"{$this->slug}\" declares an action \"{$named}\" but another screen already has that name."
                );
            }
        }
    }

    /**
     * A route per dashboard screen for its cards to fetch themselves over.
     * Added here rather than declared, because a dashboard with widgets always
     * needs exactly one and a dashboard without them never does.
     *
     * @param list<ScreenInterface> $screens
     * @return list<WidgetScreen>
     */
    private function widgetRoutes(array $screens): array
    {
        $routes = [];

        foreach ($screens as $screen) {
            if (!$screen instanceof DashboardScreen || $screen->cards() === []) {
                continue;
            }

            $named = $screen->name() . '.widget';

            foreach ($screens as $existing) {
                if ($existing->name() === $named) {
                    continue 2;
                }
            }

            $routes[] = new WidgetScreen(
                $screen->name(),
                trim(trim($screen->path(), '/') . '/w/{widget}', '/'),
                $screen->ability(),
            );
        }

        return $routes;
    }

    /**
     * Two cards at one key on one dashboard: the second is unreachable, since
     * the key is the whole of what its URL carries, and the grid would render
     * both and fill the same one twice.
     */
    private function assertWidgetsAreDistinct(): void
    {
        foreach ($this->screens as $screen) {
            if (!$screen instanceof DashboardScreen) {
                continue;
            }

            $seen = [];

            foreach ($screen->cards() as $widget) {
                if (isset($seen[$widget->key()])) {
                    throw new LogicException(sprintf(
                        'Admin module "%s" declares two dashboard widgets keyed "%s".',
                        $this->slug,
                        $widget->key(),
                    ));
                }

                $seen[$widget->key()] = true;
            }
        }
    }

    /**
     * A link is a filtered view of a source's rows and a tally of how many
     * there are, and without a source it is neither: the bar would render,
     * every link would lead to an empty table, and the count beside it would
     * never arrive. Duplicate keys are the other way the bar goes wrong — two
     * links at one URL, one of them unreachable.
     */
    private function assertLinksHaveSomethingToCount(): void
    {
        if ($this->links === []) {
            return;
        }

        if ($this->source === null) {
            throw new LogicException(sprintf(
                'Admin module "%s" declares filter links with no source to filter.',
                $this->slug,
            ));
        }

        $seen = [];

        foreach ($this->links as $link) {
            if (isset($seen[$link->key()])) {
                throw new LogicException(sprintf(
                    'Admin module "%s" declares two filter links keyed "%s".',
                    $this->slug,
                    $link->key(),
                ));
            }

            $seen[$link->key()] = true;
        }
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
     * and Field::id() is what says which column that is. Without it the row links
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

    /**
     * An export screen reads through the module's source and writes the fields
     * declared for {@see Surface::Export}. Missing either, it routes, gates,
     * puts a button on the list and then hands the visitor a 500 or a file of
     * nothing but headings, which is exactly the kind of failure the other two
     * checks here exist to move forward to the moment the module is declared.
     */
    private function assertExportHasSomethingToWrite(): void
    {
        $exports = false;
        $columns = false;

        foreach ($this->screens as $screen) {
            $exports = $exports || $screen instanceof ExportScreen;
        }

        if (!$exports) {
            return;
        }

        foreach ($this->fields as $field) {
            $columns = $columns || $field->appearsOn(Surface::Export);
        }

        $missing = match (true) {
            $this->source === null => 'no source to read',
            !$columns => 'no fields on Surface::Export to write',
            default => null,
        };

        if ($missing === null) {
            return;
        }

        throw new LogicException(sprintf(
            'Admin module "%s" declares an export screen with %s.',
            $this->slug,
            $missing,
        ));
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
