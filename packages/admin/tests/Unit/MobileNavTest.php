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
 * The narrow-screen navigation. A rail cannot simply stack above the content: a
 * deployment with thirty modules would bury every page under its own menu. So
 * the top bar is a separate control, and the sidebar it opens is the same
 * element the wide layout renders as a rail, one nav in two shapes. What holds
 * that together is that neither the link list nor the module data is duplicated.
 */
final class MobileNavTest extends TestCase
{
    public function test_the_top_bar_ships_with_the_page(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('class="admin-topbar"', $page);
        $this->assertStringContainsString('class="admin-toggle"', $page);
        $this->assertStringContainsString('class="admin-scrim"', $page);
    }

    public function test_the_button_names_the_drawer_it_opens(): void
    {
        $page = $this->page();

        // The control and the region have to agree, or a screen reader is told
        // about something that is not there.
        $this->assertStringContainsString('aria-controls="admin-sidebar"', $page);
        $this->assertStringContainsString('id="admin-sidebar"', $page);
        $this->assertStringContainsString('aria-expanded="false"', $page);
    }

    public function test_the_drawer_and_the_rail_are_one_nav_carrying_one_list(): void
    {
        $page = $this->page();

        // The whole point: a second copy would render the same modules and then
        // drift, because the out-of-band swap that marks the current one can
        // only reach a single id.
        $this->assertSame(1, substr_count($page, 'id="admin-nav"'));
        $this->assertSame(1, substr_count($page, 'id="admin-sidebar"'));

        foreach (['Overview', 'People', 'Audit', 'Settings'] as $module) {
            $this->assertStringContainsString($module, $page);
        }
    }

    public function test_navigating_still_updates_the_one_nav_out_of_band(): void
    {
        $admin = $this->admin();
        $frame = (string) $admin->controller->list($admin->request('GET', '/admin/people', $admin->frame()))->getBody();

        $this->assertSame(1, substr_count($frame, 'id="admin-nav"'));
        $this->assertStringContainsString('hx-swap-oob="true"', $frame);
        $this->assertStringNotContainsString('admin-topbar', $frame);
    }

    public function test_a_fragment_carries_no_chrome_at_all(): void
    {
        $admin = $this->admin();
        $body = (string) $admin->controller->list($admin->request('GET', '/admin/people', $admin->body()))->getBody();

        $this->assertStringNotContainsString('admin-topbar', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
    }

    private function page(): string
    {
        $admin = $this->admin();

        return (string) $admin->controller->list($admin->request('GET', '/admin/people'))->getBody();
    }

    private function admin(): AdminHarness
    {
        return new AdminHarness(
            [
                OverviewModule::class => new OverviewModule,
                PeopleModule::class => new PeopleModule,
                AuditModule::class => new AuditModule,
                SettingsModule::class => new SettingsModule,
                ArraySource::class => new ArraySource,
            ],
            [OverviewModule::class, PeopleModule::class, AuditModule::class, SettingsModule::class],
        );
    }
}
