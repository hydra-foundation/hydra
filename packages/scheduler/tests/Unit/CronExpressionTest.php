<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Scheduler\CronExpression;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CronExpression::class)]
final class CronExpressionTest extends TestCase
{
    /** 2026-09-23 is a Wednesday. */
    #[DataProvider('due')]
    public function test_it_is_due(string $expression, string $at): void
    {
        $this->assertTrue(CronExpression::parse($expression)->isDue(new DateTimeImmutable($at)));
    }

    #[DataProvider('notDue')]
    public function test_it_is_not_due(string $expression, string $at): void
    {
        $this->assertFalse(CronExpression::parse($expression)->isDue(new DateTimeImmutable($at)));
    }

    #[DataProvider('invalid')]
    public function test_a_malformed_expression_is_refused(string $expression): void
    {
        $this->expectException(InvalidArgumentException::class);

        CronExpression::parse($expression);
    }

    public function test_the_error_names_the_field_at_fault(): void
    {
        $this->expectExceptionMessage('hour field "24"');

        CronExpression::parse('0 24 * * *');
    }

    public function test_the_expression_is_kept_as_written(): void
    {
        $this->assertSame('*/5 * * * *', CronExpression::parse('  */5 * * * * ')->expression);
    }

    #[DataProvider('next')]
    public function test_the_next_run_is_the_first_matching_minute_after(string $expression, string $after, string $next): void
    {
        $at = CronExpression::parse($expression)->next(new DateTimeImmutable($after));

        $this->assertSame($next, $at?->format('Y-m-d H:i T'));
    }

    public function test_the_next_run_is_read_in_the_zone_it_is_handed(): void
    {
        $at = CronExpression::parse('0 4 * * *')->next(new DateTimeImmutable('2026-09-23 05:00', new DateTimeZone('America/New_York')));

        $this->assertSame('2026-09-24T04:00:00-04:00', $at?->format(DATE_ATOM));
    }

    public function test_a_time_skipped_by_the_clocks_going_forward_is_skipped_as_the_tick_would(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $cron = CronExpression::parse('30 2 * * *');
        $at = $cron->next(new DateTimeImmutable('2027-03-13 03:00', $zone));

        $this->assertSame('2027-03-15 02:30', $at?->format('Y-m-d H:i'));
        $this->assertFalse($cron->isDue(new DateTimeImmutable('2027-03-14 03:30', $zone)));
    }

    public function test_the_hour_the_clocks_go_back_is_not_run_backwards_into(): void
    {
        $zone = new DateTimeZone('America/New_York');
        // 01:30 EST, the second 01:30 of 2026-11-01.
        $after = (new DateTimeImmutable('@1793514600'))->setTimezone($zone);

        $at = CronExpression::parse('* * * * *')->next($after);

        $this->assertSame('01:31 EST', $at?->format('H:i T'));
        $this->assertSame(60, $at->getTimestamp() - $after->getTimestamp());
    }

    public function test_an_expression_that_never_matches_has_no_next_run(): void
    {
        $this->assertNull(CronExpression::parse('0 0 30 2 *')->next(new DateTimeImmutable('2026-09-23 00:00')));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function next(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-09-23 13:37:42 UTC', '2026-09-23 13:38 UTC'];
        yield 'strictly after' => ['0 4 * * *', '2026-09-23 04:00 UTC', '2026-09-24 04:00 UTC'];
        yield 'hourly across midnight' => ['0 * * * *', '2026-09-23 23:15 UTC', '2026-09-24 00:00 UTC'];
        yield 'a step' => ['*/15 * * * *', '2026-09-23 10:46 UTC', '2026-09-23 11:00 UTC'];
        yield 'weekly' => ['0 0 * * 0', '2026-09-23 12:00 UTC', '2026-09-27 00:00 UTC'];
        yield 'across a year' => ['0 0 1 1 *', '2026-09-23 12:00 UTC', '2027-01-01 00:00 UTC'];
        yield 'either day: the 1st' => ['0 4 1 * 1', '2026-09-29 12:00 UTC', '2026-10-01 04:00 UTC'];
        yield 'either day: a monday' => ['0 4 1 * 1', '2026-09-23 12:00 UTC', '2026-09-28 04:00 UTC'];
        yield 'a leap day' => ['0 0 29 2 *', '2026-09-23 00:00 UTC', '2028-02-29 00:00 UTC'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function due(): iterable
    {
        yield 'every minute' => ['* * * * *', '2026-09-23 13:37'];
        yield 'a fixed time' => ['0 4 * * *', '2026-09-23 04:00'];
        yield 'a step on a star' => ['*/15 * * * *', '2026-09-23 10:45'];
        yield 'a step from a start' => ['5/20 * * * *', '2026-09-23 10:45'];
        yield 'a stepped range' => ['0 9-17/4 * * *', '2026-09-23 13:00'];
        yield 'a list' => ['0 4,16 * * *', '2026-09-23 16:00'];
        yield 'a weekday range' => ['0 9 * * 1-5', '2026-09-23 09:00'];
        yield 'sunday as 0' => ['0 0 * * 0', '2026-09-27 00:00'];
        yield 'sunday as 7' => ['0 0 * * 7', '2026-09-27 00:00'];
        yield 'a month' => ['0 0 1 9 *', '2026-09-01 00:00'];
        yield 'either day: the date' => ['0 4 23 * 1', '2026-09-23 04:00'];
        yield 'either day: the weekday' => ['0 4 1 * 3', '2026-09-23 04:00'];
        yield 'a restricted date with a star weekday' => ['0 4 23 * *', '2026-09-23 04:00'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function notDue(): iterable
    {
        yield 'the wrong minute' => ['0 4 * * *', '2026-09-23 04:01'];
        yield 'off the step' => ['*/15 * * * *', '2026-09-23 10:44'];
        yield 'before a stepped start' => ['5/20 * * * *', '2026-09-23 10:00'];
        yield 'outside a range' => ['0 9-17 * * *', '2026-09-23 18:00'];
        yield 'the weekend' => ['0 9 * * 1-5', '2026-09-27 09:00'];
        yield 'another month' => ['0 0 1 9 *', '2026-10-01 00:00'];
        yield 'either day: neither' => ['0 4 1 * 1', '2026-09-23 04:00'];
        yield 'a star weekday does not widen the date' => ['0 4 1 * *', '2026-09-23 04:00'];
        yield 'a star date does not widen the weekday' => ['0 4 * * 1', '2026-09-23 04:00'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'too few fields' => ['* * * *'];
        yield 'too many fields' => ['* * * * * *'];
        yield 'empty' => [''];
        yield 'a minute past 59' => ['60 * * * *'];
        yield 'day of month 0' => ['0 0 0 * *'];
        yield 'month 13' => ['0 0 * 13 *'];
        yield 'day of week 8' => ['0 0 * * 8'];
        yield 'a backwards range' => ['0 17-9 * * *'];
        yield 'a zero step' => ['*/0 * * * *'];
        yield 'a name' => ['0 0 * * MON'];
        yield 'a shorthand' => ['@daily'];
        yield 'an empty list item' => ['0,,5 * * * *'];
        yield 'a negative' => ['-1 * * * *'];
    }
}
