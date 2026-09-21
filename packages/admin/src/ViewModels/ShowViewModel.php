<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;

/**
 * Everything a detail screen reads: the fields that surface here, the row's
 * value for each, and the way on to editing it when the module allows that.
 */
final readonly class ShowViewModel
{
    /** @param array<string, mixed> $row */
    public function __construct(
        public Blueprint $blueprint,
        public string $id,
        public string $prefix,
        private array $row = [],
        /** The reader's zone, in which stored instants become times of day. */
        private ?DateTimeZone $zone = null,
        /**
         * The view of the list this row was opened from, as a query string.
         * Back leads there rather than to the module's defaults, and the ways
         * on from here carry it so the next screen can do the same.
         */
        private string $listQuery = '',
        /** The reader's now, against which a relative() field is measured. */
        private ?DateTimeImmutable $now = null,
    ) {}

    /** @return list<Field> */
    public function fields(): array
    {
        return $this->blueprint->fieldsOn(Surface::Show);
    }

    public function value(Field $field): string|HtmlView
    {
        return $field->display(Surface::Show, $this->row, $this->zone, $this->now);
    }

    /** Where Back leads: the list as the visitor had it when they opened this row. */
    public function listUrl(): string
    {
        return $this->root() . $this->listQuery;
    }

    /** The module's own URL, which every screen of it hangs off. */
    private function root(): string
    {
        return rtrim($this->prefix, '/') . '/' . $this->blueprint->slug;
    }

    /** Where this row is edited, or null when the module declares no edit screen. */
    public function editUrl(): ?string
    {
        return $this->blueprint->screen('edit') instanceof FormScreen
            ? $this->rowUrl('edit')
            : null;
    }

    /** Where this row is deleted, or null when the module declares no delete screen. */
    public function deleteUrl(): ?string
    {
        return $this->blueprint->screen('delete') instanceof DeleteScreen
            ? $this->rowUrl('delete')
            : null;
    }

    /** What the visitor is asked before the row goes. */
    public function deletePrompt(): string
    {
        $screen = $this->blueprint->screen('delete');

        return $screen instanceof DeleteScreen ? $screen->prompt() : '';
    }

    private function rowUrl(string $screen): ?string
    {
        $path = $this->blueprint->screen($screen)?->path();

        return $path === null
            ? null
            : $this->root()
                . '/' . str_replace('{id}', rawurlencode($this->id), trim($path, '/'))
                . $this->listQuery;
    }
}
