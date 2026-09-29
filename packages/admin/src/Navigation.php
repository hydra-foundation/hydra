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
    /** @var list<array{slug: string, title: string, icon: ?string, group: ?string, url: string, members: list<string>}>|null */
    private ?array $reachable = null;

    /** @var array<string, bool> */
    private array $allowed = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly GateInterface $gate,
    ) {}

    /** @return list<array{slug: string, title: string, icon: ?string, group: ?string, url: string, active: bool}> */
    public function items(?string $current = null): array
    {
        return array_map(
            static function (array $item) use ($current): array {
                $members = $item['members'];
                unset($item['members']);

                return [...$item, 'active' => in_array($current, $members, true)];
            },
            $this->reachable(),
        );
    }

    /** Where the admin root lands: the first module this visitor may reach. */
    public function home(): string
    {
        return $this->reachable()[0]['url'] ?? rtrim($this->registry->prefix(), '/');
    }

    /**
     * The strip across the top of a family's screens: every member this
     * visitor may open, parent first, with the one being shown marked. Empty
     * for a module without tabs, and for a family the gate cuts to one, since
     * a strip with a single tab says nothing the heading does not.
     *
     * @return list<array{label: string, url: string, active: bool}>
     */
    public function tabs(string $current): array
    {
        $blueprint = $this->registry->find($current);

        if ($blueprint === null) {
            return [];
        }

        $tabs = [];

        foreach ($this->registry->family($blueprint) as $member) {
            if ($this->allows($member)) {
                $tabs[] = [
                    'label' => $member->title,
                    'url' => $this->registry->root($member),
                    'active' => $member->slug === $current,
                ];
            }
        }

        return count($tabs) > 1 ? $tabs : [];
    }

    /**
     * One entry per family, in declaration order. Which screen is current does
     * not change the list, only which entry is marked. A screen asks for both
     * the list and the root it hangs under, so the gate is walked once rather
     * than once per question.
     *
     * An entry carries its parent's name, icon and group and sits at the
     * parent's place, since that is what the family is called, but it leads to
     * the first member this visitor may open: a visitor denied Jobs and
     * allowed Failed jobs still has a way in, and never one that answers 403.
     *
     * Held for the life of this instance, which is one visitor's: the gate
     * answers for whoever is signed in, and that is settled before a screen is
     * built and does not change while one is being rendered.
     *
     * @return list<array{slug: string, title: string, icon: ?string, group: ?string, url: string, members: list<string>}>
     */
    private function reachable(): array
    {
        if ($this->reachable !== null) {
            return $this->reachable;
        }

        $items = [];

        foreach ($this->registry->all() as $blueprint) {
            if ($blueprint->tabOf !== null) {
                continue;
            }

            $family = $this->registry->family($blueprint);
            $members = array_values(array_filter($family, $this->allows(...)));

            if ($members === []) {
                continue;
            }

            $items[] = [
                'slug' => $blueprint->slug,
                'title' => $blueprint->title,
                'icon' => $blueprint->icon,
                'group' => $blueprint->group,
                'url' => $this->registry->root($members[0]),
                'members' => array_map(static fn (Blueprint $member): string => $member->slug, $family),
            ];
        }

        return $this->reachable = $items;
    }

    private function allows(Blueprint $blueprint): bool
    {
        return $this->allowed[$blueprint->slug] ??= $blueprint->ability === null || $this->gate->allows($blueprint->ability);
    }
}
