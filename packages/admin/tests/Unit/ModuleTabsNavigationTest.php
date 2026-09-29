<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Chrome;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DeclaredModule;
use Hydra\Admin\ViewModels\ScreenViewModel;
use Hydra\Authorization\Testing\FakeGate;
use Hydra\Core\Testing\FakeContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A family of modules is one sidebar entry and a strip of tabs, and both are
 * cut down by the gate member by member: no entry, tab or landing page may
 * lead a visitor to a screen that would answer 403.
 *
 * The fixture registers Failed jobs ahead of Jobs on purpose, so the entry's
 * place in the sidebar is shown to come from the parent and not from
 * whichever member happened to be registered first.
 */
#[CoversClass(Navigation::class)]
#[CoversClass(Chrome::class)]
#[CoversClass(ScreenViewModel::class)]
final class ModuleTabsNavigationTest extends TestCase
{
    public function test_a_family_is_one_sidebar_entry_at_its_parents_place(): void
    {
        $items = $this->navigation()->items();

        $this->assertSame(['dashboard', 'jobs', 'users'], array_column($items, 'slug'));
        $this->assertSame(
            ['slug' => 'jobs', 'title' => 'Jobs', 'icon' => 'hourglass', 'group' => 'Queue', 'url' => '/admin/jobs', 'active' => false],
            $items[1],
        );
    }

    public function test_the_entry_is_active_on_every_member_of_the_family(): void
    {
        foreach (['jobs', 'failed-jobs', 'batches'] as $current) {
            $items = $this->navigation()->items($current);

            $this->assertSame([false, true, false], array_column($items, 'active'), "on {$current}");
        }
    }

    /**
     * The entry keeps the parent's name, since that is what the family is
     * called, but leads to a member the visitor may open.
     */
    public function test_a_denied_parent_leaves_the_entry_leading_to_the_first_allowed_tab(): void
    {
        $items = $this->navigation(FakeGate::allowingEverything()->deny('SeeJobs'))->items();

        $this->assertSame(['dashboard', 'jobs', 'users'], array_column($items, 'slug'));
        $this->assertSame('Jobs', $items[1]['title']);
        $this->assertSame('/admin/failed-jobs', $items[1]['url']);
    }

    public function test_the_first_allowed_tab_follows_registration_order(): void
    {
        $items = $this->navigation(FakeGate::allowingEverything()->deny('SeeJobs', 'SeeFailures'))->items();

        $this->assertSame('/admin/batches', $items[1]['url']);
    }

    public function test_a_family_with_no_member_allowed_has_no_entry(): void
    {
        $items = $this->navigation(FakeGate::allowingEverything()->deny('SeeJobs', 'SeeFailures', 'SeeBatches'))->items();

        $this->assertSame(['dashboard', 'users'], array_column($items, 'slug'));
    }

    public function test_the_admin_root_lands_on_a_tab_only_when_it_is_the_way_in(): void
    {
        $gate = FakeGate::denyingEverything()->allow('SeeFailures');

        $this->assertSame('/admin/failed-jobs', $this->navigation($gate, withDashboard: false)->home());
        $this->assertSame('/admin/jobs', $this->navigation(withDashboard: false)->home());
    }

    public function test_the_tabs_are_the_family_in_order_with_the_current_one_marked(): void
    {
        $this->assertSame(
            [
                ['label' => 'Jobs', 'url' => '/admin/jobs', 'active' => false],
                ['label' => 'Failed jobs', 'url' => '/admin/failed-jobs', 'active' => true],
                ['label' => 'Batches', 'url' => '/admin/batches', 'active' => false],
            ],
            $this->navigation()->tabs('failed-jobs'),
        );
    }

    /**
     * The strip names each list; the entry and the heading name the family.
     * A parent called after the family ("Access") can say what its own list
     * is in the strip ("API tokens") without renaming the entry.
     */
    public function test_a_tab_label_names_the_tab_and_nothing_else(): void
    {
        $registry = $this->registry(labels: ['jobs' => 'Waiting']);
        $navigation = new Navigation($registry, FakeGate::allowingEverything());
        $chrome = new Chrome($registry, $navigation);
        $jobs = $registry->find('jobs') ?? self::fail('jobs is not registered');

        $this->assertSame(['Waiting', 'Failed jobs', 'Batches'], array_column($navigation->tabs('jobs'), 'label'));
        $this->assertSame('Jobs', $navigation->items()[1]['title']);
        $this->assertSame('Jobs', $chrome->module($jobs)->family());
        $this->assertSame(['Admin', 'Queue', 'Jobs'], array_column($chrome->module($jobs)->breadcrumbs, 'label'));
    }

    public function test_a_tab_the_gate_denies_is_left_out_of_the_strip(): void
    {
        $tabs = $this->navigation(FakeGate::allowingEverything()->deny('SeeFailures'))->tabs('jobs');

        $this->assertSame(['Jobs', 'Batches'], array_column($tabs, 'label'));
    }

    /** A strip with one tab in it says nothing the heading does not. */
    public function test_a_family_the_gate_cuts_to_one_member_has_no_strip(): void
    {
        $gate = FakeGate::allowingEverything()->deny('SeeFailures', 'SeeBatches');

        $this->assertSame([], $this->navigation($gate)->tabs('jobs'));
    }

    public function test_a_module_without_tabs_has_no_strip(): void
    {
        $this->assertSame([], $this->navigation()->tabs('users'));
    }

    public function test_every_screen_of_a_member_carries_the_strip(): void
    {
        $chrome = $this->chrome();

        $this->assertSame('Failed jobs', $chrome->module($this->blueprint('failed-jobs'))->tabs[1]['label']);
        $this->assertTrue($chrome->screen($this->blueprint('failed-jobs'), 'Failed job', '42')->tabs[1]['active']);
        $this->assertSame([], $chrome->module($this->blueprint('users'))->tabs);
        $this->assertSame([], $chrome->root('Admin')->tabs);
    }

    public function test_a_screen_is_named_after_the_entry_it_sits_under(): void
    {
        $chrome = $this->chrome();

        $this->assertSame('Jobs', $chrome->module($this->blueprint('failed-jobs'))->family());
        $this->assertSame('Somewhere', $chrome->root('Somewhere')->family());
    }

    /**
     * A tab declares no group, so its trail borrows the parent's: Failed jobs
     * sits under Queue as Jobs does, rather than hanging straight off the root.
     */
    public function test_a_tab_takes_its_breadcrumb_group_from_its_parent(): void
    {
        $chrome = $this->chrome();

        $this->assertSame(
            ['Admin', 'Queue', 'Failed jobs'],
            array_column($chrome->module($this->blueprint('failed-jobs'))->breadcrumbs, 'label'),
        );
        $this->assertSame(
            ['Admin', 'Queue', 'Failed jobs', '42'],
            array_column($chrome->screen($this->blueprint('failed-jobs'), 'Failed job', '42')->breadcrumbs, 'label'),
        );
    }

    /**
     * The out-of-band swap that moves the highlight on an htmx navigation
     * renders from the same items, so one entry is marked on a tab too.
     */
    public function test_the_sidebar_marks_the_parent_on_a_tab(): void
    {
        $groups = $this->chrome()->module($this->blueprint('batches'))->groups();
        $queue = $groups[array_search('Queue', array_column($groups, 'title'), true)];

        $this->assertSame([['Jobs', true]], array_map(
            static fn (array $item): array => [$item['title'], $item['active']],
            $queue['items'],
        ));
    }

    /**
     * A screen asks for the entries, the landing page and the strip, and a
     * family's members come up in each. The gate may be a database query per
     * ability, so each is asked once per visitor, however many times the
     * answer is needed.
     */
    public function test_the_gate_is_asked_about_each_ability_once(): void
    {
        $gate = FakeGate::allowingEverything();
        $navigation = $this->navigation($gate);

        $navigation->items('jobs');
        $navigation->items('failed-jobs');
        $navigation->home();
        $navigation->tabs('jobs');
        $navigation->tabs('batches');

        $asked = array_column($gate->checks(), 'ability');
        sort($asked);

        $this->assertSame(['SeeBatches', 'SeeFailures', 'SeeJobs', 'SeeUsers'], $asked);
    }

    private function navigation(?FakeGate $gate = null, bool $withDashboard = true): Navigation
    {
        return new Navigation($this->registry($withDashboard), $gate ?? FakeGate::allowingEverything());
    }

    private function chrome(?FakeGate $gate = null): Chrome
    {
        $registry = $this->registry();

        return new Chrome($registry, new Navigation($registry, $gate ?? FakeGate::allowingEverything()));
    }

    private function blueprint(string $slug): Blueprint
    {
        return $this->registry()->find($slug) ?? self::fail("No module registered at \"{$slug}\".");
    }

    /** @param array<string, string> $labels a tab label per slug */
    private function registry(bool $withDashboard = true, array $labels = []): ModuleRegistry
    {
        $list = static fn (string $slug): Definition => Definition::make($slug)
            ->source(new ArraySource)
            ->fields(Field::id());

        $modules = [
            'dashboard' => $list('dashboard'),
            'failed-jobs' => $list('failed-jobs')->title('Failed jobs')->ability('SeeFailures')->tabOf('jobs'),
            'jobs' => $list('jobs')->title('Jobs')->icon('hourglass')->group('Queue')->ability('SeeJobs'),
            'users' => $list('users')->group('Administration')->ability('SeeUsers'),
            'batches' => $list('batches')->ability('SeeBatches')->tabOf('jobs'),
        ];

        if (!$withDashboard) {
            unset($modules['dashboard']);
        }

        foreach ($labels as $slug => $label) {
            $modules[$slug] = $modules[$slug]->tabLabel($label);
        }

        return new ModuleRegistry(
            new FakeContainer(array_map(static fn (Definition $definition): DeclaredModule => new DeclaredModule($definition), $modules)),
            array_keys($modules),
        );
    }
}
