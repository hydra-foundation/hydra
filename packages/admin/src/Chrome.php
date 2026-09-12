<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\ViewModels\ScreenViewModel;

/**
 * Builds the frame around a screen. Modules get it automatically; an ordinary
 * controller asks for one and renders into the same layout.
 */
final class Chrome
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Navigation $navigation,
    ) {}

    /** A screen sitting at the admin root, with nothing above it. */
    public function root(string $title): ScreenViewModel
    {
        return new ScreenViewModel(
            $title,
            $this->navigation->items(),
            [['label' => $title, 'url' => null]],
        );
    }

    /** A module's own screen, hung under the admin root. */
    public function module(Blueprint $blueprint, ?string $title = null, ?Notice $notice = null): ScreenViewModel
    {
        return new ScreenViewModel(
            $title ?? $blueprint->title,
            $this->navigation->items($blueprint->slug),
            [
                $this->home(),
                ...$this->under($blueprint),
                ['label' => $title ?? $blueprint->title, 'url' => null],
            ],
            $notice,
        );
    }

    /**
     * A screen below a module, with the module's own root above it. The crumb
     * names the row where the title names the task: "Edit 42" under "Edit user".
     */
    public function screen(Blueprint $blueprint, string $title, ?string $crumb = null, ?Notice $notice = null): ScreenViewModel
    {
        return new ScreenViewModel(
            $title,
            $this->navigation->items($blueprint->slug),
            [
                $this->home(),
                ...$this->under($blueprint),
                ['label' => $blueprint->title, 'url' => $this->registry->root($blueprint)],
                ['label' => $crumb ?? $title, 'url' => null],
            ],
            $notice,
        );
    }

    /**
     * The sidebar heading a module sits under, as a crumb with nothing behind
     * it: a group is a label, not a screen, so there is nowhere for it to go.
     * A module that declared no group contributes no crumb.
     *
     * @return list<array{label: string, url: null}>
     */
    private function under(Blueprint $blueprint): array
    {
        return $blueprint->group === null
            ? []
            : [['label' => $blueprint->group, 'url' => null]];
    }

    /**
     * The root crumb points at the landing module rather than the prefix, which
     * only redirects there, and htmx answers a redirect by reloading the page.
     *
     * @return array{label: string, url: string}
     */
    private function home(): array
    {
        return ['label' => 'Admin', 'url' => $this->navigation->home()];
    }
}
