<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

use Hydra\Admin\Notice;

/**
 * The chrome around any admin screen: sidebar, breadcrumbs, title, and the
 * notice a write leaves behind. It knows nothing about tables or forms, so a
 * plain controller action can render inside the admin layout without pretending
 * to be a module.
 */
final readonly class ScreenViewModel
{
    /**
     * @param list<array{slug: string, title: string, icon: ?string, group: ?string, url: string, active: bool}> $navigation
     * @param list<array{label: string, url: ?string}> $breadcrumbs
     */
    public function __construct(
        public string $title,
        public array $navigation,
        public array $breadcrumbs,
        public ?Notice $notice = null,
    ) {}

    /**
     * The sidebar's shape: the same modules, under the headings they declared.
     * A group appears where its first module does, so the order a module list
     * is written in is the order it reads in. Ungrouped modules keep a heading
     * of null and are rendered bare, which is every module until somebody
     * declares a group.
     *
     * Derived rather than passed in, so the menu and the cards on the dashboard
     * cannot disagree about what this visitor may reach.
     *
     * @return list<array{title: ?string, items: list<array<string, mixed>>}>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->navigation as $item) {
            $groups[$item['group'] ?? ''][] = $item;
        }

        return array_values(array_map(
            static fn (string $title, array $items): array => [
                'title' => $title === '' ? null : $title,
                'items' => $items,
            ],
            array_keys($groups),
            $groups,
        ));
    }
}
