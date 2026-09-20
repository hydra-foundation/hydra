<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Period;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\StatsPresenter;
use Hydra\Admin\Tests\Support\TrendPresenter;
use Hydra\Admin\Tests\Support\WidgetDashboardModule;
use Hydra\Admin\Widget;
use Hydra\Admin\Window;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stretch of time a dashboard is asking about: where it comes from, which
 * cards follow it, and what stays put when it changes.
 */
#[CoversClass(Period::class)]
#[CoversClass(Window::class)]
final class DashboardPeriodTest extends TestCase
{
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->admin = $this->harness();
    }

    public function test_the_grid_offers_the_period_and_defaults_to_one(): void
    {
        $body = $this->dashboard();

        $this->assertStringContainsString('id="admin-period"', $body);
        $this->assertStringContainsString('value="today" selected', $body);
        $this->assertStringContainsString('All time', $body);
    }

    public function test_a_periodic_card_carries_the_period_in_its_own_url(): void
    {
        // The address is the whole question, which is what lets the refresh
        // button beside it know nothing about the dropdown.
        $this->assertStringContainsString('/admin/overview/w/trend?period=month', $this->dashboard('?period=month'));
    }

    public function test_a_card_about_all_time_is_not_given_a_period(): void
    {
        $body = $this->dashboard('?period=month');

        $this->assertStringContainsString('"/admin/overview/w/accounts"', $body);
        $this->assertStringNotContainsString('/admin/overview/w/accounts?period', $body);
    }

    public function test_the_period_reaches_the_presenter(): void
    {
        $this->assertStringContainsString('Last 30 days', $this->widget('/admin/overview/w/trend?period=month'));
    }

    public function test_a_period_nothing_answers_to_falls_back_to_the_default(): void
    {
        $this->assertStringContainsString('Today', $this->widget('/admin/overview/w/trend?period=nonsense'));
    }

    public function test_the_totals_survive_a_change_of_period(): void
    {
        // hx-preserve is what keeps the one thing on the page the period cannot
        // affect from being the thing that reloads most.
        $this->assertStringContainsString('hx-preserve="true"', $this->dashboard());
    }

    public function test_what_is_preserved_never_also_replaces_itself(): void
    {
        // Preserving means "keep the node already here and drop the one that
        // arrived". An element that also swaps itself asks htmx to do both to
        // one node, which it attempts and fails at, taking the summary's first
        // fetch down with a removeChild on a parent it no longer has.
        $preserved = $this->tag($this->dashboard(), 'hx-preserve');

        $this->assertStringNotContainsString('hx-get', $preserved);
        $this->assertStringNotContainsString('hx-swap', $preserved);
    }

    /** The one opening tag carrying $attribute, without its contents. */
    private function tag(string $html, string $attribute): string
    {
        $this->assertSame(1, substr_count($html, $attribute), "expected one {$attribute}");

        $start = strrpos(substr($html, 0, strpos($html, $attribute) ?: 0), '<');
        $this->assertIsInt($start);

        return substr($html, $start, (strpos($html, '>', $start) ?: $start) - $start + 1);
    }

    public function test_the_summary_is_fetched_like_any_other_card(): void
    {
        $presenter = new StatsPresenter;
        $admin = $this->harness(stats: $presenter);

        $this->assertSame(0, $presenter->calls);
        $body = (string) $admin->controller->widget($admin->request('GET', '/admin/overview/w/totals'))->getBody();

        $this->assertSame(1, $presenter->calls);
        $this->assertStringContainsString('admin-stat-figure', $body);
        $this->assertStringContainsString('42', $body);
    }

    public function test_a_dashboard_whose_cards_are_all_about_all_time_offers_no_period(): void
    {
        $this->assertStringNotContainsString('id="admin-period"', $this->dashboard('', periodic: false));
    }

    public function test_a_card_declared_periodic_whose_presenter_cannot_be_told_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is declared periodic');

        $this->admin->registry->presentWidget(
            Widget::make('trend', 'admin/widgets/count')->periodic()->from(CountPresenter::class),
            Period::Week->window(new FrozenClock),
        );
    }

    public function test_a_presenter_that_reads_a_window_on_a_card_that_never_sets_one_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not implement');

        $this->admin->registry->presentWidget(
            Widget::make('trend', 'admin/widgets/count')->from(TrendPresenter::class),
            Period::Week->window(new FrozenClock),
        );
    }

    public function test_a_rolling_period_ends_open_and_a_calendar_one_does_not(): void
    {
        $clock = new FrozenClock;

        $week = Period::Week->window($clock);
        $yesterday = Period::Yesterday->window($clock);

        $this->assertNotNull($week->since);
        $this->assertNull($week->until);
        $this->assertNotNull($yesterday->until);
        $this->assertSame('00:00:00', $yesterday->since?->format('H:i:s'));
    }

    public function test_all_time_reads_as_a_condition_that_excludes_nothing(): void
    {
        // An empty string would have to be glued onto the query by hand, and
        // "WHERE" with nothing after it is a syntax error at the far end.
        $this->assertSame(['1 = 1', []], Period::All->window(new FrozenClock)->condition('created_at'));
    }

    public function test_a_bounded_period_binds_both_ends(): void
    {
        [$sql, $bindings] = Period::Yesterday->window(new FrozenClock)->condition('a.created_at');

        $this->assertSame('a.created_at >= ? AND a.created_at < ?', $sql);
        $this->assertCount(2, $bindings);
    }

    public function test_a_column_that_is_not_one_is_refused_rather_than_written_into_the_sql(): void
    {
        $this->expectExceptionMessage('is not a column a window can be read over');

        Period::Week->window(new FrozenClock)->condition('created_at = 1 OR 1');
    }

    private function dashboard(string $query = '', bool $periodic = true): string
    {
        $admin = $periodic ? $this->admin : $this->harness(periodic: false);

        return (string) $admin->controller->dashboard(
            $admin->request('GET', '/admin/overview' . $query),
        )->getBody();
    }

    private function widget(string $path): string
    {
        return (string) $this->admin->controller->widget($this->admin->request('GET', $path))->getBody();
    }

    private function harness(bool $periodic = true, ?StatsPresenter $stats = null): AdminHarness
    {
        $module = $periodic ? new WidgetDashboardModule : new WidgetDashboardModule(periodic: false);

        return new AdminHarness(
            [
                WidgetDashboardModule::class => $module,
                CountPresenter::class => new CountPresenter,
                TrendPresenter::class => new TrendPresenter,
                StatsPresenter::class => $stats ?? new StatsPresenter,
            ],
            [WidgetDashboardModule::class],
        );
    }
}
