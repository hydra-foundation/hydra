<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\LandingModule;
use PHPUnit\Framework\TestCase;

/**
 * The landing page an admin has before anybody writes one. It renders what the
 * package can know on its own — the modules this visitor may reach — so an
 * application that declares a page screen and no template still has a working
 * front door rather than a 500 on it.
 */
final class ShippedDashboardTest extends TestCase
{
    public function test_it_offers_every_other_module_the_visitor_may_reach(): void
    {
        $body = $this->render();

        $this->assertStringContainsString('Users', $body);
        $this->assertStringContainsString('/admin/users', $body);
    }

    public function test_it_does_not_offer_the_page_the_visitor_is_already_on(): void
    {
        $body = $this->render();

        $this->assertStringNotContainsString('/admin/home', $body);
    }

    public function test_a_module_the_gate_refuses_is_not_offered(): void
    {
        // The same rule the sidebar follows: a link may never appear for a
        // screen that would 403 when it is followed.
        $body = $this->render(allowed: false);

        $this->assertStringNotContainsString('/admin/users', $body);
        $this->assertStringContainsString('Nothing here yet', $body);
    }

    public function test_it_renders_without_a_presenter_or_any_data_of_its_own(): void
    {
        // The whole point: the module declares a page screen and nothing else.
        $admin = $this->admin();

        $this->assertSame([], $admin->registry->present(
            $admin->registry->find('home')->screen('overview'),
        ));
        $this->assertStringContainsString('card', $this->render());
    }

    public function test_a_page_screen_is_handed_the_screen_at_every_depth(): void
    {
        // The contract the dashboard rests on: a body template receives the
        // screen view model, not only whatever a presenter returned — and it
        // cannot tell which depth it is being rendered at, so both must.
        $admin = $this->admin();

        foreach ([$admin->frame(), $admin->body(), []] as $headers) {
            $body = (string) $admin->controller
                ->page($admin->request('GET', '/admin/home', $headers))
                ->getBody();

            $this->assertStringContainsString('/admin/users', $body);
        }
    }

    private function render(bool $allowed = true): string
    {
        $admin = $this->admin($allowed);

        return (string) $admin->controller
            ->page($admin->request('GET', '/admin/home', $admin->body()))
            ->getBody();
    }

    private function admin(bool $allowed = true): AdminHarness
    {
        return new AdminHarness(
            [
                LandingModule::class => new LandingModule,
                CrudUsersModule::class => new CrudUsersModule,
                CrudUserSource::class => new CrudUserSource,
            ],
            [LandingModule::class, CrudUsersModule::class],
            allowed: $allowed,
        );
    }
}
