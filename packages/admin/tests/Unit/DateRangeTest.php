<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\ColumnName;
use Hydra\Admin\DateRange;
use Hydra\Admin\FieldType;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A From and a To day off a list's query string. Both are typed by a visitor,
 * so a bad one is dropped rather than refused, and both are days in the
 * reader's zone, which is where a day starts and ends.
 */
#[CoversClass(DateRange::class)]
#[CoversClass(ColumnName::class)]
final class DateRangeTest extends TestCase
{
    private const ZONE = 'America/Edmonton';

    public function test_nothing_asked_for_is_no_range(): void
    {
        $this->assertNull($this->range(null, null));
        $this->assertNull($this->range('', ''));
    }

    /** @return iterable<string, array{string}> */
    public static function badDays(): iterable
    {
        yield 'words' => ['yesterday'];
        yield 'no such day' => ['2026-02-30'];
        yield 'month thirteen' => ['2026-13-01'];
        yield 'no padding' => ['2026-1-5'];
        yield 'a time as well' => ['2026-10-05 12:00'];
        yield 'trailing junk' => ['2026-10-05x'];
        yield 'five-digit year' => ['20260-10-05'];
    }

    #[DataProvider('badDays')]
    public function test_a_day_that_is_not_a_day_is_dropped(string $day): void
    {
        $this->assertNull($this->range($day, null));

        $range = $this->range($day, '2026-10-05');

        $this->assertNotNull($range);
        $this->assertNull($range->fromValue());
        $this->assertSame('2026-10-05', $range->toValue());
    }

    public function test_ends_given_backwards_are_swapped(): void
    {
        $range = $this->range('2026-10-05', '2026-10-01');

        $this->assertNotNull($range);
        $this->assertSame('2026-10-01', $range->fromValue());
        $this->assertSame('2026-10-05', $range->toValue());
    }

    public function test_a_datetime_range_is_half_open_in_utc_from_the_readers_midnights(): void
    {
        // Edmonton is UTC-6 in October: its midnight is 06:00 UTC, and the
        // whole of the 5th runs up to 06:00 UTC on the 6th.
        $this->assertSame(
            ['created_at >= ? AND created_at < ?', ['2026-10-01 06:00:00', '2026-10-06 06:00:00']],
            $this->range('2026-10-01', '2026-10-05')?->condition('created_at'),
        );
    }

    public function test_one_end_is_one_clause(): void
    {
        $this->assertSame(['created_at >= ?', ['2026-10-01 06:00:00']], $this->range('2026-10-01', null)?->condition('created_at'));
        $this->assertNull($this->range('2026-10-01', null)?->toValue());
        $this->assertNull($this->range(null, '2026-10-05')?->fromValue());
        $this->assertSame(['created_at < ?', ['2026-10-06 06:00:00']], $this->range(null, '2026-10-05')?->condition('created_at'));
    }

    public function test_a_date_range_compares_days_with_no_zone_in_it(): void
    {
        $range = DateRange::fromDays('2026-10-01', '2026-10-05', FieldType::Date, new DateTimeZone(self::ZONE));

        $this->assertSame(['due_on >= ? AND due_on <= ?', ['2026-10-01', '2026-10-05']], $range?->condition('due_on'));
    }

    public function test_the_last_day_crosses_daylight_saving_on_the_readers_clock(): void
    {
        // 2 November 2025 was 25 hours long in Toronto: it began at 04:00 UTC
        // and the day after it began at 05:00, not 04:00.
        $range = DateRange::fromDays('2025-11-02', '2025-11-02', FieldType::DateTime, new DateTimeZone('America/Toronto'));

        $this->assertSame(
            ['created_at >= ? AND created_at < ?', ['2025-11-02 04:00:00', '2025-11-03 05:00:00']],
            $range?->condition('created_at'),
        );
    }

    public function test_an_evening_row_belongs_to_the_readers_day_not_utcs(): void
    {
        $range = $this->range('2026-10-05', '2026-10-05');
        $this->assertNotNull($range);

        // 22:30 on the 5th in Edmonton is already the 6th in UTC.
        $this->assertTrue($range->contains(new DateTimeImmutable('2026-10-06 04:30:00', new DateTimeZone('UTC'))));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-10-05 05:59:59', new DateTimeZone('UTC'))));
    }

    public function test_contains_agrees_with_the_condition_at_both_edges(): void
    {
        $range = $this->range('2026-10-01', '2026-10-05');
        $this->assertNotNull($range);
        $zone = new DateTimeZone(self::ZONE);

        $this->assertFalse($range->contains(new DateTimeImmutable('2026-09-30 23:59:59', $zone)));
        $this->assertTrue($range->contains(new DateTimeImmutable('2026-10-01 00:00:00', $zone)));
        $this->assertTrue($range->contains(new DateTimeImmutable('2026-10-05 23:59:59', $zone)));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-10-06 00:00:00', $zone)));
    }

    public function test_an_open_end_contains_everything_on_that_side(): void
    {
        $zone = new DateTimeZone(self::ZONE);

        $this->assertTrue($this->range('2026-10-01', null)?->contains(new DateTimeImmutable('2099-01-01', $zone)));
        $this->assertTrue($this->range(null, '2026-10-01')?->contains(new DateTimeImmutable('1970-01-02', $zone)));
    }

    public function test_a_date_range_contains_by_the_day_alone(): void
    {
        $range = DateRange::fromDays('2026-10-01', '2026-10-05', FieldType::Date, new DateTimeZone(self::ZONE));
        $this->assertNotNull($range);

        $this->assertTrue($range->contains(new DateTimeImmutable('2026-10-05 23:59:59')));
        $this->assertTrue($range->contains(new DateTimeImmutable('2026-10-01 00:00:00')));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-10-06 00:00:00')));
        $this->assertFalse($range->contains(new DateTimeImmutable('2026-09-30 23:59:59')));
    }

    public function test_only_a_date_or_a_datetime_has_a_range(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only a date or a datetime');

        DateRange::fromDays('2026-10-01', null, FieldType::Number, new DateTimeZone(self::ZONE));
    }

    public function test_the_column_is_held_to_a_columns_shape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a column a date range can be read over');

        $this->range('2026-10-01', null)?->condition('created_at = 1 OR 1');
    }

    public function test_a_qualified_column_is_a_column(): void
    {
        $this->assertSame(['s.created_at >= ?', ['2026-10-01 06:00:00']], $this->range('2026-10-01', null)?->condition('s.created_at'));
    }

    private function range(?string $from, ?string $to): ?DateRange
    {
        return DateRange::fromDays($from, $to, FieldType::DateTime, new DateTimeZone(self::ZONE));
    }
}
