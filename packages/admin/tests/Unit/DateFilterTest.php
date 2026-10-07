<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Criteria;
use Hydra\Admin\FixedTimezone;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\DatedModule;
use Hydra\Admin\Tests\Support\DatedSource;
use Hydra\Admin\ViewModels\ListViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A list narrowed by day, end to end: the days are the reader's, and the range
 * rides along on every URL the list draws, so that paging, a filter link's
 * tally and the export all answer about the same days the table shows.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(Criteria::class)]
#[CoversClass(ListViewModel::class)]
final class DateFilterTest extends TestCase
{
    /** Six hours behind UTC and no daylight saving. */
    private const ZONE = 'America/Regina';

    private const FIFTH = 'happened_at_from=2026-10-05&happened_at_to=2026-10-05';

    public function test_a_day_is_the_readers_day(): void
    {
        $admin = $this->admin(self::ZONE);

        // Regina's 5th runs from 06:00 UTC on the 5th to 06:00 on the 6th.
        $this->assertStringContainsString('crashed', $this->list($admin, '?' . self::FIFTH));
        $this->assertStringContainsString('deployed', $this->list($admin, '?' . self::FIFTH . '&page=2'));
        $this->assertStringContainsString('of 2', $this->list($admin, '?' . self::FIFTH));
    }

    public function test_without_a_zone_a_day_is_utcs(): void
    {
        $admin = $this->admin();

        $this->assertStringContainsString('booted', $this->list($admin, '?' . self::FIFTH));
        $this->assertStringContainsString('crashed', $this->list($admin, '?' . self::FIFTH . '&page=2'));
    }

    public function test_the_pager_carries_the_range(): void
    {
        $body = $this->list($this->admin(self::ZONE), '?' . self::FIFTH);

        $this->assertStringContainsString('happened_at_from=2026-10-05&amp;happened_at_to=2026-10-05&amp;page=2', $body);
    }

    public function test_a_filter_link_counts_inside_the_range(): void
    {
        $admin = $this->admin(self::ZONE);
        // Regina's 6th holds only the timeout; UTC's would hold the deploy too.
        $body = (string) $admin->controller->counts(
            $admin->request('GET', '/admin/events/counts?happened_at_from=2026-10-06&happened_at_to=2026-10-06'),
        )->getBody();

        $this->assertMatchesRegularExpression('/All\s*<span[^>]*>1</', $body);
        $this->assertMatchesRegularExpression('/Errors\s*<span[^>]*>1</', $body);
    }

    public function test_the_export_holds_the_days_the_table_shows(): void
    {
        $admin = $this->admin(self::ZONE);
        $csv = (string) $admin->controller->export($admin->request('GET', '/admin/events/export?' . self::FIFTH))->getBody();

        $this->assertStringContainsString('crashed', $csv);
        $this->assertStringContainsString('deployed', $csv);
        $this->assertStringNotContainsString('booted', $csv);
        $this->assertStringNotContainsString('timed out', $csv);
    }

    public function test_a_range_and_an_exact_filter_narrow_together(): void
    {
        $body = $this->list($this->admin(self::ZONE), '?' . self::FIFTH . '&kind=info');

        $this->assertStringContainsString('deployed', $body);
        $this->assertStringContainsString('of 1', $body);
    }

    public function test_the_toolbar_offers_a_from_and_a_to_day_under_the_fields_label(): void
    {
        $body = $this->list($this->admin(self::ZONE), '?happened_at_from=2026-10-05');

        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*name="happened_at_from"[^>]*value="2026-10-05"/', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*type="date"[^>]*name="happened_at_to"[^>]*value=""/', $body);
        $this->assertStringContainsString('>Happened<', $body);
        $this->assertStringNotContainsString('name="happened_at"', $body, 'a range is never an exact match');
    }

    public function test_each_day_is_labelled_for_a_screen_reader(): void
    {
        $body = $this->list($this->admin(), '');

        $this->assertMatchesRegularExpression('/<label[^>]*for="admin-filter-happened_at-from"[^>]*>\s*Happened from\s*</', $body);
        $this->assertMatchesRegularExpression('/<label[^>]*for="admin-filter-happened_at-to"[^>]*>\s*Happened to\s*</', $body);
    }

    public function test_a_day_typed_digit_by_digit_asks_once(): void
    {
        // A date input fires change for every complete value, and typing a
        // year passes through 0002, 0020 and 0202 on the way to 2026. Asked
        // for at once, those raced and the list could settle on year 20.
        $body = $this->list($this->admin(), '');

        $this->assertMatchesRegularExpression('/<form[^>]*hx-trigger="[^"]*\bchange delay:\d+ms/', $body);
    }

    public function test_an_exact_filter_still_draws_as_before(): void
    {
        $this->assertStringContainsString('id="admin-filter-kind"', $this->list($this->admin(), ''));
    }

    public function test_the_links_counts_and_export_carry_the_range(): void
    {
        $body = $this->list($this->admin(self::ZONE), '?' . self::FIFTH);
        $range = 'happened_at_from=2026-10-05&amp;happened_at_to=2026-10-05';

        $this->assertMatchesRegularExpression('#/admin/events\?[^"]*' . preg_quote($range, '#') . '[^"]*view=errors#', $body);
        $this->assertStringContainsString('/admin/events/counts?' . $range, $body);
        $this->assertMatchesRegularExpression('#/admin/events/export\?[^"]*' . preg_quote($range, '#') . '#', $body);
    }

    private function admin(?string $zone = null): AdminHarness
    {
        return new AdminHarness(
            [DatedModule::class => new DatedModule, DatedSource::class => new DatedSource],
            [DatedModule::class],
            timezone: $zone === null ? new FixedTimezone : new FixedTimezone($zone),
        );
    }

    private function list(AdminHarness $admin, string $query): string
    {
        return (string) $admin->controller->list($admin->request('GET', '/admin/events' . $query))->getBody();
    }
}
