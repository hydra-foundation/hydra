<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use DateTimeImmutable;
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
