<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Field;
use Hydra\Admin\Link;
use Hydra\Admin\Page;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;

/**
 * Everything a table reads: its columns, its rows, and the links that carry list
 * state through the query string so every view of the table is its own URL. The
 * surrounding chrome is {@see ScreenViewModel}'s job.
 */
final readonly class ListViewModel
{
    public function __construct(
        public Blueprint $blueprint,
        public Page $page,
        public string $prefix,
        /**
         * Spent on the counts URL to put it beyond the browser's cache, which
         * a write needs and a plain read does not. {@see countsUrl()}.
         */
        public ?string $countsToken = null,
        /** The reader's zone, in which stored instants become times of day. */
        public ?DateTimeZone $zone = null,
        /** The reader's now, against which a relative() field is measured. */
        public ?DateTimeImmutable $now = null,
    ) {}

    public function url(): string
    {
        return rtrim($this->prefix, '/') . '/' . $this->blueprint->slug;
    }

    /** @return list<Field> */
    public function columns(): array
    {
        return $this->blueprint->fieldsOn(Surface::List);
    }

    /** @return list<Field> */
    public function filters(): array
    {
        return $this->blueprint->filterable();
    }

    /** @return list<Link> */
    public function links(): array
    {
        return $this->blueprint->links;
    }

    public function isActiveLink(Link $link): bool
    {
        return ($this->page->criteria->view?->key() ?? $this->standingView()) === $link->key();
    }

    /**
     * The link the list is already on before anybody clicks one. A module that
     * offers an "All" declares it as a link pinning nothing, and that is the
     * same list the module's own URL serves, so arriving at it and clicking the
     * link are the same place — but only the second names a view, which left
     * the bar drawn with nothing current until the visitor clicked the link
     * they were already looking at.
     *
     * Null when every link pins something: there the unfiltered list is a view
     * the bar does not offer, and none of them is current.
     */
    private function standingView(): ?string
    {
        foreach ($this->links() as $link) {
            if ($link->filters() === []) {
                return $link->key();
            }
        }

        return null;
    }

    /**
     * Where this link's view of the list lives. The search and the order come
     * along, because they are the visitor's and not the link's; the toolbar
     * filters the link pins do not, so that clicking it settles the question
     * rather than losing to whatever a select was left on.
     */
    public function linkUrl(Link $link): string
    {
        return $this->link([
            'view' => $link->key(),
            'page' => null,
            ...array_map(static fn (): null => null, $link->filters()),
        ]);
    }

    /**
     * Where the tally beside each link is fetched from, or null when the module
     * declares none. It carries the search and the toolbar filters so the
     * numbers answer for the table in front of the visitor, and drops the page,
     * which is the one thing a count is not about. The active link stays on:
     * each tally pins its own link regardless, and the fragment that comes back
     * replaces this bar, so it has to know which one to draw as current.
     */
    public function countsUrl(): ?string
    {
        $screen = $this->blueprint->screen('counts');

        if ($screen === null || $this->links() === []) {
            return null;
        }

        // The order rows would come back in cannot change how many there are,
        // so sort and dir only ever put one answer behind several URLs. The
        // view is a different matter and stays: no tally is counted through it,
        // but the bar that comes back is rendered from it, and without it every
        // link returns drawn as not current.
        $query = $this->page->criteria->toQuery();
        unset($query['page'], $query['sort'], $query['dir']);

        // Underscored because nothing reads it: it exists to be different from
        // last time, and a plain name could one day be a column somebody wants
        // to filter by.
        if ($this->countsToken !== null) {
            $query['_fresh'] = $this->countsToken;
        }

        $url = $this->url() . '/' . trim($screen->path(), '/');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    public function isSearchable(): bool
    {
        return $this->blueprint->searchable() !== [];
    }

    public function filterValue(Field $field): string
    {
        return $this->page->criteria->filters[$field->name()] ?? '';
    }

    public function search(): string
    {
        return $this->page->criteria->search ?? '';
    }

    /**
     * Where this row is edited, or null when the module declares no edit screen
     * or nothing that identifies a row.
     *
     * @param array<string, mixed> $row
     */
    public function editUrl(array $row): ?string
    {
        return $this->isEditable() ? $this->rowUrl('edit', $row) : null;
    }

    /**
     * Where this row is read on its own, or null on the same terms.
     *
     * @param array<string, mixed> $row
     */
    public function showUrl(array $row): ?string
    {
        return $this->isViewable() ? $this->rowUrl('show', $row) : null;
    }

    /** Where a new row is written, or null when the module declares no create screen. */
    public function createUrl(): ?string
    {
        $screen = $this->blueprint->screen('create');

        return $screen instanceof FormScreen
            ? $this->url() . '/' . trim($screen->path(), '/') . $this->listQuery()
            : null;
    }

    /** The create screen's own title, so the button says what it opens. */
    public function createLabel(): string
    {
        $screen = $this->blueprint->screen('create');

        return ($screen instanceof FormScreen ? $screen->heading() : null) ?? 'New';
    }

    /**
     * Where this view of the list downloads as a file, or null when the module
     * declares no export screen.
     *
     * It carries the filters, the search and the order, because what the
     * visitor means by "export this" is the table in front of them. It does not
     * carry the page: the file is the whole view, and a link that exported
     * rows 51 to 75 because that is where the pager was left would be a trap.
     */
    public function exportUrl(): ?string
    {
        $screen = $this->blueprint->screen('export');

        if (!$screen instanceof ExportScreen) {
            return null;
        }

        $query = $this->page->criteria->toQuery();
        unset($query['page']);
        $url = $this->url() . '/' . trim($screen->path(), '/');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    /** The export screen's own wording, so the button says what it hands over. */
    public function exportLabel(): string
    {
        $screen = $this->blueprint->screen('export');

        return $screen instanceof ExportScreen ? $screen->label() : 'Export CSV';
    }

    /**
     * Where this row is deleted, or null on the same terms as the others.
     *
     * @param array<string, mixed> $row
     */
    public function deleteUrl(array $row): ?string
    {
        $screen = $this->blueprint->screen('delete');

        return $screen instanceof DeleteScreen && $screen->shows($row) ? $this->rowUrl('delete', $row) : null;
    }

    public function deleteLabel(): string
    {
        $screen = $this->blueprint->screen('delete');

        return $screen instanceof DeleteScreen ? $screen->label() : 'Delete';
    }

    /**
     * The row's own action buttons, less the ones its when() turns away.
     *
     * @param array<string, mixed> $row
     * @return list<ActionButton>
     */
    public function rowActions(array $row): array
    {
        $buttons = [];

        foreach ($this->actions(rowScoped: true) as $screen) {
            $url = $screen->shows($row) ? $this->rowUrl($screen->name(), $row) : null;

            if ($url !== null) {
                $buttons[] = new ActionButton($url, $screen->label(), $screen->prompt());
            }
        }

        return $buttons;
    }

    /** What the visitor is asked before a row goes. */
    public function deletePrompt(): string
    {
        $screen = $this->blueprint->screen('delete');

        return $screen instanceof DeleteScreen ? $screen->prompt() : '';
    }

    /** Whether any row action needs a column of its own. */
    public function hasRowActions(): bool
    {
        return $this->isEditable() || $this->isViewable() || $this->isDeletable() || $this->actions(rowScoped: true) !== [];
    }

    /** @param array<string, mixed> $row */
    public function cell(Field $field, array $row): string|HtmlView
    {
        return $field->display(Surface::List, $row, $this->zone, $this->now);
    }

    /** 'asc' or 'desc' when the table is ordered by this field, null otherwise. */
    public function sortedBy(Field $field): ?string
    {
        return $this->page->criteria->sort === $field->name()
            ? $this->page->criteria->direction
            : null;
    }

    public function sortLink(Field $field): string
    {
        return $this->link([
            'sort' => $field->name(),
            'dir' => $this->sortedBy($field) === 'asc' ? 'desc' : 'asc',
            'page' => null,
        ]);
    }

    public function pageLink(int $page): string
    {
        return $this->link(['page' => $page > 1 ? (string) $page : null]);
    }

    /**
     * The page numbers worth rendering around the current one: a run of at most
     * 2 * radius + 1, centred on the current page where there is room and slid
     * against either end where there is not, so the pager keeps its width
     * instead of shrinking as the visitor reaches the edges.
     *
     * @return list<int>
     */
    public function pageWindow(int $radius = 2): array
    {
        $last = $this->page->pages();
        $width = $radius * 2 + 1;

        $first = min(
            max(1, $this->page->criteria->page - $radius),
            max(1, $last - $width + 1),
        );

        return range($first, min($last, $first + $width - 1));
    }

    /**
     * Whether the module declares each row action. Nothing outside asks: a
     * template reads the url, and null already says the action is not offered.
     */
    private function isEditable(): bool
    {
        return $this->blueprint->screen('edit') instanceof FormScreen;
    }

    private function isViewable(): bool
    {
        return $this->blueprint->screen('show') instanceof ShowScreen;
    }

    private function isDeletable(): bool
    {
        return $this->blueprint->screen('delete') instanceof DeleteScreen;
    }

    /** @return list<ActionScreen> */
    private function actions(bool $rowScoped): array
    {
        return array_values(array_filter(
            $this->blueprint->screens,
            static fn ($screen): bool => $screen instanceof ActionScreen && $screen->isRowScoped() === $rowScoped,
        ));
    }

    /**
     * A named screen's path with this row's id in it, or null when nothing in
     * the row identifies it.
     *
     * @param array<string, mixed> $row
     */
    private function rowUrl(string $screen, array $row): ?string
    {
        $key = $this->blueprint->identifier();
        $id = $key === null ? null : ($row[$key] ?? null);
        $path = $this->blueprint->screen($screen)?->path();

        if (!is_scalar($id) || $path === null) {
            return null;
        }

        return $this->url()
            . '/' . str_replace('{id}', rawurlencode((string) $id), trim($path, '/'))
            . $this->listQuery();
    }

    /**
     * The view of the list a row screen is opened from, carried on the URL that
     * opens it.
     *
     * A row lives at a URL of its own, and until now that URL said nothing about
     * the table it was reached through: opening one and leaving it again landed
     * the visitor on the module's defaults, with whatever filter link, search,
     * order or page they had the list narrowed to silently discarded. The screen
     * that comes back hands this to its own Back and Cancel, so leaving a row
     * returns to the table the visitor was actually looking at.
     */
    private function listQuery(): string
    {
        return $this->page->criteria->queryString($this->blueprint);
    }

    /** @param array<string, string|null> $overrides */
    private function link(array $overrides): string
    {
        $params = array_filter(
            [...$this->page->criteria->toQuery(), ...$overrides],
            static fn (?string $value): bool => $value !== null && $value !== '',
        );

        return $params === [] ? $this->url() : $this->url() . '?' . http_build_query($params);
    }
}
