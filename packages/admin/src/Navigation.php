<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Authorization\Contracts\GateInterface;

/**
 * The sidebar, built from the same blueprints the routes came from and filtered
 * through the gate, so a link can never appear for a screen that would 403.
 */
final class Navigation
{
    /** @var list<array{slug: string, title: string, icon: ?string, group: ?string, url: string}>|null */
    private ?array $reachable = null;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly GateInterface $gate,
    ) {}

    /** @return list<array{slug: string, title: string, icon: ?string, group: ?string, url: string, active: bool}> */
    public function items(?string $current = null): array
    {
        return array_map(
            static fn (array $item): array => [...$item, 'active' => $item['slug'] === $current],
            $this->reachable(),
        );
    }

    /** Where the admin root lands: the first module this visitor may reach. */
    public function home(): string
    {
        return $this->reachable()[0]['url'] ?? rtrim($this->registry->prefix(), '/');
    }

    /**
     * The modules this visitor may open, in declaration order. Which screen is
     * current does not change the list, only which entry is marked. A screen
     * asks for both the list and the root it hangs under, so the gate is walked
     * once rather than once per question.
     *
     * Held for the life of this instance, which is one visitor's: the gate
     * answers for whoever is signed in, and that is settled before a screen is
     * built and does not change while one is being rendered.
     *
     * @return list<array{slug: string, title: string, icon: ?string, group: ?string, url: string}>
     */
    private function reachable(): array
    {
        if ($this->reachable !== null) {
            return $this->reachable;
        }

        $items = [];

        foreach ($this->registry->all() as $blueprint) {
            if ($blueprint->ability !== null && $this->gate->denies($blueprint->ability)) {
                continue;
            }

            $items[] = [
                'slug' => $blueprint->slug,
                'title' => $blueprint->title,
                'icon' => $blueprint->icon,
                'group' => $blueprint->group,
                'url' => $this->registry->root($blueprint),
            ];
        }

        return $this->reachable = $items;
    }
}
