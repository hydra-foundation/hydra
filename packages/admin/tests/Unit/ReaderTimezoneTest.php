<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeZone;
use Hydra\Admin\Csv;
use Hydra\Admin\Field;
use Hydra\Admin\FixedTimezone;
use Hydra\Admin\Period;
use Hydra\Admin\Surface;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\StampedModule;
use Hydra\Admin\Tests\Support\StampedSource;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Rows are stored as instants in UTC and read by people who are not in UTC.
 *
 * The screens shift them into the reader's zone and the windows a dashboard
 * counts over start at the reader's midnight, because a day that began at
 * 6pm the previous evening is not a day anybody recognises. What leaves as a
 * file does not shift, because it is going somewhere the reader is not.
 */
#[CoversClass(FixedTimezone::class)]
#[CoversClass(Field::class)]
final class ReaderTimezoneTest extends TestCase
{
    /** Six hours behind UTC and no daylight saving, so the arithmetic is the assertion. */
    private const ZONE = 'America/Regina';

    public function test_the_default_zone_is_the_one_rows_are_stored_in(): void
    {
        $this->assertSame('UTC', (new FixedTimezone)->zone()->getName());
    }

    public function test_a_stored_instant_reads_as_a_local_time(): void
    {
        $field = Field::datetime('created_at');

        $this->assertSame(
            '2026-01-01 03:30:00',
            $field->display(Surface::List, ['created_at' => '2026-01-01T09:30:00+00:00'], new DateTimeZone(self::ZONE)),
        );
    }

    public function test_an_instant_read_without_a_zone_is_left_alone(): void
    {
        $field = Field::datetime('created_at');

        $this->assertSame(
            '2026-01-01T09:30:00+00:00',
            $field->display(Surface::List, ['created_at' => '2026-01-01T09:30:00+00:00']),
        );
    }

    public function test_only_a_datetime_is_shifted(): void
    {
        // Nothing about "09:30" says it is a time, and a text column that
        // happened to parse as one would come back rewritten.
        $field = Field::text('note');

        $this->assertSame(
            '2026-01-01T09:30:00+00:00',
            $field->display(Surface::List, ['note' => '2026-01-01T09:30:00+00:00'], new DateTimeZone(self::ZONE)),
        );
    }

    public function test_a_column_that_is_not_a_time_survives_being_declared_one(): void
    {
        $field = Field::datetime('created_at');

        $this->assertSame(
            'never',
            $field->display(Surface::List, ['created_at' => 'never'], new DateTimeZone(self::ZONE)),
        );
    }

    public function test_an_empty_stamp_stays_empty(): void
    {
        $field = Field::datetime('created_at');

        $this->assertSame('', $field->display(Surface::List, ['created_at' => ''], new DateTimeZone(self::ZONE)));
    }

    public function test_a_list_renders_its_stamps_where_the_reader_is(): void
    {
        $html = (string) $this->stamps()->controller->list(
            $this->stamps()->request('GET', '/admin/stamps'),
        )->getBody();

        $this->assertStringContainsString('2026-01-01 03:30:00', $html);
        $this->assertStringNotContainsString('2026-01-01T09:30:00', $html);
    }

    public function test_a_row_renders_its_stamps_where_the_reader_is(): void
    {
        $admin = $this->stamps();

        $this->assertStringContainsString('2026-01-01 03:30:00', (string) $admin->controller->show(
            $admin->request('GET', '/admin/stamps/1', $admin->frame()),
        )->getBody());
    }

    public function test_a_download_keeps_the_zone_the_rows_are_stored_in(): void
    {
        // The file goes to a spreadsheet or to somebody else's desk, where the
        // reader's zone is not on offer to read it back by.
        $admin = $this->stamps();

        $csv = (string) $admin->controller->export(
            $admin->request('GET', '/admin/stamps/export'),
        )->getBody();

        $this->assertStringContainsString('2026-01-01T09:30:00+00:00', $csv);
        $this->assertStringNotContainsString('03:30:00', $csv);
    }

    public function test_a_download_asked_for_directly_is_unshifted_too(): void
    {
        $csv = Csv::render(
            [Field::datetime('created_at')],
            [['created_at' => '2026-01-01T09:30:00+00:00']],
        );

        $this->assertStringContainsString('2026-01-01T09:30:00+00:00', $csv);
    }

    public function test_today_begins_at_the_readers_midnight_and_is_bound_as_utc(): void
    {
        // 03:15 on the 2nd in UTC is 21:15 on the 1st in Regina, so "today"
        // there is a day the UTC calendar has already left.
        $window = Period::Today->window(
            new FrozenClock('2026-01-02T03:15:00+00:00'),
            new DateTimeZone(self::ZONE),
        );

        $this->assertSame(['created_at >= ?', ['2026-01-01 06:00:00']], $window->condition('created_at'));
    }

    public function test_a_reader_in_utc_gets_the_utc_midnight(): void
    {
        $window = Period::Today->window(
            new FrozenClock('2026-01-02T03:15:00+00:00'),
            new DateTimeZone('UTC'),
        );

        $this->assertSame(['created_at >= ?', ['2026-01-02 00:00:00']], $window->condition('created_at'));
    }

    public function test_yesterday_is_the_readers_whole_day(): void
    {
        [$sql, $bindings] = Period::Yesterday->window(
            new FrozenClock('2026-01-02T03:15:00+00:00'),
            new DateTimeZone(self::ZONE),
        )->condition('created_at');

        $this->assertSame('created_at >= ? AND created_at < ?', $sql);
        $this->assertSame(['2025-12-31 06:00:00', '2026-01-01 06:00:00'], $bindings);
    }

    public function test_a_rolling_period_does_not_wait_for_midnight(): void
    {
        // Nothing about "the last 7 days" needs a zone, so naming one must not
        // move the boundary.
        $clock = new FrozenClock('2026-01-02T03:15:00+00:00');

        $this->assertSame(
            Period::Week->window($clock)->condition('created_at'),
            Period::Week->window($clock, new DateTimeZone(self::ZONE))->condition('created_at'),
        );
    }

    private function stamps(): AdminHarness
    {
        return new AdminHarness(
            [StampedModule::class => new StampedModule, StampedSource::class => new StampedSource],
            [StampedModule::class],
            timezone: new FixedTimezone(self::ZONE),
        );
    }
}
