<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\AuditModule;
use Hydra\Admin\Tests\Support\OverviewModule;
use Hydra\Admin\Tests\Support\PeopleModule;
use Hydra\Admin\Tests\Support\SettingsModule;
use PHPUnit\Framework\TestCase;

/**
 * A group is how the sidebar is organised and nothing else: modules under one
 * heading need not relate to each other, and the heading itself is not a page.
 */
final class NavigationGroupTest extends TestCase
{
    public function test_modules_are_gathered_under_the_headings_they_declared(): void
    {
        $groups = $this->menu();

        $this->assertSame([null, 'Administration', 'System'], array_column($groups, 'title'));
        $this->assertSame(['Overview'], $this->titles($groups[0]));
        $this->assertSame(['People', 'Audit'], $this->titles($groups[1]));
        $this->assertSame(['Settings'], $this->titles($groups[2]));
    }

    public function test_a_group_appears_where_its_first_module_does(): void
    {
        // People is declared second and Audit fourth, with Settings between
        // them: Administration takes People's place, not Audit's.
        $this->assertSame('Administration', $this->menu(order: 'interleaved')[1]['title']);
        $this->assertSame(['People', 'Audit'], $this->titles($this->menu(order: 'interleaved')[1]));
    }

    public function test_a_module_that_declared_no_group_is_rendered_bare(): void
    {
        $body = $this->sidebar();

        // Overview declared no group, so it is listed above the first heading
        // rather than under one of its own.
        $this->assertStringContainsString('/admin/overview', $body);
        $this->assertLessThan(strpos($body, '<h2'), strpos($body, '/admin/overview'));
    }

    public function test_a_group_names_the_step_between_the_root_and_the_module(): void
    {
        $admin = $this->admin();
        $screen = $admin->chrome->module($admin->registry->find('people'));

        $this->assertSame(['Admin', 'Administration', 'People'], array_column($screen->breadcrumbs, 'label'));

        // A heading is a label and not a page, so the crumb goes nowhere.
        $this->assertNull($screen->breadcrumbs[1]['url']);
    }

    public function test_a_group_stays_above_a_screen_below_the_module(): void
    {
        $admin = $this->admin();
        $screen = $admin->chrome->screen($admin->registry->find('people'), 'Edit person', 'Edit 42');

        $this->assertSame(['Admin', 'Administration', 'People', 'Edit 42'], array_column($screen->breadcrumbs, 'label'));
        $this->assertSame('/admin/people', $screen->breadcrumbs[2]['url']);
    }

    public function test_a_module_that_declared_no_group_hangs_straight_off_the_root(): void
    {
        $admin = $this->admin();
        $screen = $admin->chrome->module($admin->registry->find('overview'));

        $this->assertSame(['Admin', 'Overview'], array_column($screen->breadcrumbs, 'label'));
    }

    public function test_only_the_crumb_the_visitor_is_on_is_marked_active(): void
    {
        $admin = $this->admin();
        $html = (string) $admin->controller->list($admin->request('GET', '/admin/people', $admin->frame()))->getBody();

        // A group crumb has no url either, and having none was once enough to
        // be styled as the page the visitor is looking at.
        $this->assertSame(1, substr_count($html, 'breadcrumb-item active'));
        $this->assertMatchesRegularExpression('~breadcrumb-item active">\s*People~', $html);
    }

    public function test_a_heading_is_rendered_once_for_the_modules_beneath_it(): void
    {
        $body = $this->sidebar();

        $this->assertSame(1, substr_count($body, 'Administration'));
        $this->assertStringContainsString('/admin/people', $body);
        $this->assertStringContainsString('/admin/audit', $body);
    }

    public function test_a_heading_disappears_when_the_gate_empties_it(): void
    {
        // A group is only its modules; nothing about it is declared separately,
        // so a visitor who may reach none of them sees no heading either, and
        // what they may still reach is unaffected.
        $admin = $this->admin(allowed: false);
        $groups = $admin->chrome->root('Admin')->groups();

        $this->assertSame([null], array_column($groups, 'title'));
        $this->assertSame(['Overview'], $this->titles($groups[0]));
    }

    /** @return list<array{title: ?string, items: list<array<string, mixed>>}> */
    private function menu(string $order = 'declared'): array
    {
        $admin = $this->admin($order);

        return $admin->chrome->module($admin->registry->find('people'))->groups();
    }

    private function sidebar(): string
    {
        $admin = $this->admin();

        return $admin->view->render('admin/partials/nav', [
            'screen' => $admin->chrome->module($admin->registry->find('people')),
            'oob' => false,
        ], layout: false);
    }

    /**
     * @param array{items: list<array<string, mixed>>} $group
     * @return list<string>
     */
    private function titles(array $group): array
    {
        return array_column($group['items'], 'title');
    }

    private function admin(string $order = 'declared', bool $allowed = true): AdminHarness
    {
        $modules = $order === 'interleaved'
            ? [OverviewModule::class, PeopleModule::class, SettingsModule::class, AuditModule::class]
            : [OverviewModule::class, PeopleModule::class, AuditModule::class, SettingsModule::class];

        return new AdminHarness(
            [
                OverviewModule::class => new OverviewModule,
                PeopleModule::class => new PeopleModule,
                AuditModule::class => new AuditModule,
                SettingsModule::class => new SettingsModule,
                ArraySource::class => new ArraySource,
            ],
            $modules,
            allowed: $allowed,
        );
    }
}
