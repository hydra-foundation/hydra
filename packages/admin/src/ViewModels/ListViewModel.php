<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Field;
use Hydra\Admin\Page;
use Hydra\Admin\Screens\DeleteScreen;
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
            ? $this->url() . '/' . trim($screen->path(), '/')
            : null;
    }

    /** The create screen's own title, so the button says what it opens. */
    public function createLabel(): string
    {
        $screen = $this->blueprint->screen('create');

        return ($screen instanceof FormScreen ? $screen->heading() : null) ?? 'New';
    }

    /**
     * Where this row is deleted, or null on the same terms as the others.
     *
     * @param array<string, mixed> $row
     */
    public function deleteUrl(array $row): ?string
    {
        return $this->isDeletable() ? $this->rowUrl('delete', $row) : null;
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
        return $this->isEditable() || $this->isViewable() || $this->isDeletable();
    }

    /** @param array<string, mixed> $row */
    public function cell(Field $field, array $row): string|HtmlView
    {
        return $field->display(Surface::List, $row);
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

        return $this->url() . '/' . str_replace('{id}', rawurlencode((string) $id), trim($path, '/'));
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
