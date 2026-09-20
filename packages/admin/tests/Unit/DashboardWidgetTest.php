<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Screens\WidgetScreen;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\WidgetDashboardModule;
use Hydra\Admin\Widget;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\NotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A dashboard of widgets: the grid arrives with no queries behind it, each card
 * fetches its own body afterwards, and an ability on a card is enforced at the
 * card's own URL rather than only where the grid drew it.
 */
#[CoversClass(Widget::class)]
#[CoversClass(DashboardScreen::class)]
#[CoversClass(WidgetScreen::class)]
final class DashboardWidgetTest extends TestCase
{
    private CountPresenter $presenter;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->presenter = new CountPresenter;
        $this->admin = $this->harness();
    }

    public function test_the_grid_renders_every_card_and_runs_none_of_their_queries(): void
    {
        $body = $this->dashboard();

        $this->assertStringContainsString('Accounts', $body);
        $this->assertStringContainsString('Live', $body);
        // The whole point of the split: the page is done before a widget is.
        $this->assertSame(0, $this->presenter->calls);
        $this->assertStringNotContainsString('7 accounts', $body);
    }

    public function test_every_card_carries_the_url_it_fetches_itself_from(): void
    {
        $body = $this->dashboard();

        $this->assertStringContainsString('/admin/overview/w/accounts', $body);
        $this->assertStringContainsString('/admin/overview/w/live', $body);
        $this->assertStringContainsString('hx-trigger="load"', $body);
    }

    public function test_a_declared_width_reaches_the_grid(): void
    {
        $this->assertStringContainsString('col-lg-4', $this->dashboard());
        // The default, for the card that did not ask for one.
        $this->assertStringContainsString('col-lg-6', $this->dashboard());
    }

    public function test_a_card_comes_back_filled(): void
    {
        $body = $this->widget('/admin/overview/w/accounts');

        $this->assertStringContainsString('7 accounts', $body);
        $this->assertStringContainsString('Accounts', $body);
        $this->assertSame(1, $this->presenter->calls);
    }

    public function test_a_card_that_does_not_refresh_does_not_ask_again(): void
    {
        // Its own hx-trigger is what ends the exchange; a filled card carrying
        // one would fetch itself forever. The refresh button beside the heading
        // has an hx-get of its own and waits to be pressed, so the question is
        // what fires by itself rather than what the card mentions.
        $this->assertStringNotContainsString('hx-trigger', $this->widget('/admin/overview/w/accounts'));
    }

    public function test_a_filled_card_offers_to_be_asked_again(): void
    {
        $body = $this->widget('/admin/overview/w/accounts');

        $this->assertStringContainsString('admin-widget-refresh', $body);
        $this->assertStringContainsString('hx-target="#admin-widget-accounts"', $body);
        $this->assertStringContainsString('aria-label="Refresh Accounts"', $body);
    }

    public function test_a_card_still_fetching_is_not_offered_a_refresh(): void
    {
        // It is already doing what the button asks.
        $this->assertStringNotContainsString('admin-widget-refresh', $this->dashboard());
    }

    public function test_a_card_that_refreshes_comes_back_still_polling(): void
    {
        $body = $this->widget('/admin/overview/w/live');

        $this->assertStringContainsString('hx-trigger="every 30s"', $body);
        $this->assertStringContainsString('/admin/overview/w/live', $body);
    }

    public function test_a_card_the_dashboard_does_not_declare_is_not_found(): void
    {
        $this->expectException(NotFoundException::class);

        $this->widget('/admin/overview/w/nothing');
    }

    public function test_a_card_the_gate_refuses_is_not_drawn(): void
    {
        $admin = $this->harness(allowed: false);
        $body = (string) $admin->controller->dashboard($admin->request('GET', '/admin/overview'))->getBody();

        $this->assertStringNotContainsString('Secret', $body);
        $this->assertStringContainsString('Accounts', $body);
    }

    public function test_a_card_the_gate_refuses_is_refused_at_its_own_url_too(): void
    {
        // Leaving it off the grid is not withholding it: the URL is reachable
        // on its own, and that is where the answer has to be given.
        $admin = $this->harness(allowed: false);

        $this->expectException(AuthorizationException::class);

        $admin->controller->widget($admin->request('GET', '/admin/overview/w/secret'));
    }

    public function test_declaring_widgets_routes_one_way_to_fetch_them(): void
    {
        $blueprint = (new WidgetDashboardModule)->define()->compile();

        $this->assertInstanceOf(WidgetScreen::class, $blueprint->screen('dashboard.widget'));
        $this->assertSame('w/{widget}', $blueprint->screen('dashboard.widget')?->path());
    }

    public function test_a_dashboard_with_no_widgets_is_given_no_route_for_them(): void
    {
        $blueprint = Definition::make('overview')
            ->screens(DashboardScreen::make())
            ->compile();

        $this->assertNull($blueprint->screen('dashboard.widget'));
    }

    public function test_two_cards_at_one_key_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('two dashboard widgets keyed "same"');

        Definition::make('overview')
            ->screens(DashboardScreen::make()->widgets(
                Widget::make('same', 'admin/widgets/count'),
                Widget::make('same', 'admin/widgets/count'),
            ))
            ->compile();
    }

    public function test_a_width_outside_the_grid_is_clamped_to_it(): void
    {
        $this->assertSame(12, Widget::make('w', 't')->spanning(99)->width());
        $this->assertSame(1, Widget::make('w', 't')->spanning(0)->width());
    }

    private function dashboard(): string
    {
        return (string) $this->admin->controller
            ->dashboard($this->admin->request('GET', '/admin/overview'))
            ->getBody();
    }

    private function widget(string $path): string
    {
        return (string) $this->admin->controller
            ->widget($this->admin->request('GET', $path))
            ->getBody();
    }

    private function harness(bool $allowed = true): AdminHarness
    {
        return new AdminHarness(
            [
                WidgetDashboardModule::class => new WidgetDashboardModule,
                CountPresenter::class => $this->presenter,
            ],
            [WidgetDashboardModule::class],
            allowed: $allowed,
        );
    }
}
