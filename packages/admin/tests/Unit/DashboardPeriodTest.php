<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeImmutable;
use Hydra\Admin\Period;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CountPresenter;
use Hydra\Admin\Tests\Support\PeriodicStatsPresenter;
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

    public function test_a_strip_that_answers_for_all_time_survives_a_change_of_period(): void
    {
        // What a change of period actually asks for. This strip is not about
        // the period, so the change leaves it alone — and it is left alone by
        // where it renders rather than by a marker asking htmx to spare it,
        // which is one fewer instruction to get right.
        $admin = $this->admin;

        $swapped = $this->swap($admin);

        $this->assertStringContainsString('admin-widgets', $swapped);
        $this->assertStringNotContainsString('admin-summary-card', $swapped);
    }

    public function test_a_strip_that_follows_the_period_comes_back_with_the_grid(): void
    {
        // Sitting outside the swap is what keeps the select alive across it;
        // for a strip that does follow the period it is also what makes it
        // stale. Out of band is how both are true at once, and it goes back
        // empty so it fetches the new figures the way it did on first paint.
        $swapped = $this->swap($this->harness(summaryPeriodic: true));

        $this->assertStringContainsString('admin-widgets', $swapped);
        $this->assertStringContainsString('id="admin-summary-card" hx-swap-oob="true"', $swapped);
        $this->assertStringContainsString('aria-busy="true"', $swapped);
    }

    public function test_a_strip_that_follows_the_period_is_reason_enough_to_offer_one(): void
    {
        // The control is offered off the whole page, not off the grid: a page
        // whose headline figures move with the period and whose cards do not
        // still has a period, and without this it had no way to set it.
        $body = $this->dashboard('', periodic: false, summaryPeriodic: true);

        $this->assertStringContainsString('id="admin-period"', $body);
    }

    public function test_the_control_is_not_in_what_it_replaces(): void
    {
        // A select inside its own target is destroyed by its own answer: focus
        // goes to the document, and in a browser that fires change on arrow
        // keys the control is torn out from under the keystroke.
        $this->assertStringNotContainsString('id="admin-period"', $this->swap($this->admin));
    }

    public function test_a_body_swap_says_what_the_cards_are_now_answering_for(): void
    {
        // The line beside the control is the one part of the toolbar the swap
        // makes stale, so it comes back out of band. It is also the only thing
        // that announces the change: the grid is replaced silently.
        $swapped = $this->swap($this->admin);

        $this->assertStringContainsString('hx-swap-oob="true"', $swapped);
        $this->assertStringContainsString('Showing last 30 days', $swapped);
    }

    public function test_a_dashboard_with_no_period_sends_no_stale_line_out_of_band(): void
    {
        // An out-of-band swap addressed to an element the page does not have is
        // one htmx cannot land.
        $this->assertStringNotContainsString('hx-swap-oob', $this->swap($this->harness(periodic: false)));
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

    public function test_every_period_reads_as_dates_a_reader_can_tell_apart(): void
    {
        // The whole job of the line: the select says "Last 7 days" and "Last 30
        // days" in the same six characters, and this is where they stop looking
        // alike. No clock time in any of them — a rolling window starts at
        // whatever o'clock it is now, and saying so buys nothing but noise.
        $clock = new FrozenClock;

        $this->assertSame([
            'Today' => '1 Jan 2026',
            'Yesterday' => '31 Dec 2025',
            'Last 7 days' => '25 Dec 2025 – 1 Jan 2026',
            'Last 30 days' => '2 Dec 2025 – 1 Jan 2026',
            'Last 12 months' => '1 Jan 2025 – 1 Jan 2026',
            'All time' => null,
        ], array_combine(
            array_map(fn (Period $period) => $period->label(), Period::cases()),
            array_map(fn (Period $period) => $period->window($clock)->range(), Period::cases()),
        ));
    }

    public function test_a_window_that_was_never_told_when_now_was_says_only_where_it_starts(): void
    {
        // Rather than reading the clock behind the caller's back to finish the
        // sentence, which is how one card names an end its own query stopped
        // short of.
        $since = new DateTimeImmutable('2026-01-01 09:30:00');

        $this->assertSame('Since 1 Jan 2026', (new Window(Period::Week, $since))->range());
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

    private function dashboard(string $query = '', bool $periodic = true, bool $summaryPeriodic = false): string
    {
        $admin = $periodic && !$summaryPeriodic ? $this->admin : $this->harness($periodic, $summaryPeriodic);

        return (string) $admin->controller->dashboard(
            $admin->request('GET', '/admin/overview' . $query),
        )->getBody();
    }

    /** The dashboard as the period select asks for it: a body swap, nothing else. */
    private function swap(AdminHarness $admin): string
    {
        return (string) $admin->controller->dashboard(
            $admin->request('GET', '/admin/overview?period=month', $admin->body()),
        )->getBody();
    }

    private function widget(string $path): string
    {
        return (string) $this->admin->controller->widget($this->admin->request('GET', $path))->getBody();
    }

    private function harness(
        bool $periodic = true,
        bool $summaryPeriodic = false,
        ?StatsPresenter $stats = null,
    ): AdminHarness {
        return new AdminHarness(
            [
                WidgetDashboardModule::class => new WidgetDashboardModule($periodic, $summaryPeriodic),
                CountPresenter::class => new CountPresenter,
                TrendPresenter::class => new TrendPresenter,
                StatsPresenter::class => $stats ?? new StatsPresenter,
                PeriodicStatsPresenter::class => new PeriodicStatsPresenter,
            ],
            [WidgetDashboardModule::class],
        );
    }
}
