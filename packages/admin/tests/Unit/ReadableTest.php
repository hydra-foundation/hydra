<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Widgets\Readable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The figures a health card shows. Pinned because a wrong one is still a
 * plausible one: nothing throws when a gigabyte prints as a megabyte.
 */
#[CoversClass(Readable::class)]
final class ReadableTest extends TestCase
{
    #[DataProvider('sizes')]
    public function test_a_size_climbs_to_the_largest_unit_that_leaves_a_whole_number(?float $bytes, ?string $expected): void
    {
        $this->assertSame($expected, Readable::bytes($bytes));
    }

    /** @return iterable<string, array{float|null, string|null}> */
    public static function sizes(): iterable
    {
        yield 'nothing measured' => [null, null];
        yield 'zero' => [0.0, '0B'];
        yield 'bytes never take a decimal' => [512.0, '512B'];
        yield 'a small figure keeps one' => [1536.0, '1.5KB'];
        yield 'a large one does not' => [1024.0 ** 2 * 512, '512MB'];
        yield 'and it stops at the largest unit' => [1024.0 ** 6, '1024PB'];
    }

    #[DataProvider('durations')]
    public function test_a_duration_shows_the_two_largest_units_that_say_anything(int $seconds, string $expected): void
    {
        $this->assertSame($expected, Readable::duration($seconds));
    }

    /** @return iterable<string, array{int, string}> */
    public static function durations(): iterable
    {
        yield 'just started' => [0, '0s'];
        yield 'seconds' => [45, '45s'];
        yield 'minutes and seconds' => [570, '9m 30s'];
        yield 'hours and minutes' => [7380, '2h 3m'];
        yield 'days and hours' => [187200, '2d 4h'];
        // The zero is kept because it is between two units that are not zero:
        // dropping it would print two days and three minutes as "2d 3m".
        yield 'a unit that is zero in the middle' => [172980, '2d 0h'];
    }

    #[DataProvider('latencies')]
    public function test_a_fast_round_trip_does_not_round_away_to_nothing(float $ms, string $expected): void
    {
        $this->assertSame($expected, Readable::millis($ms));
    }

    /** @return iterable<string, array{float, string}> */
    public static function latencies(): iterable
    {
        yield 'sub-millisecond' => [0.14, '0.1ms'];
        yield 'single figure' => [4.55, '4.6ms'];
        yield 'beyond ten, the decimal is noise' => [82.4, '82ms'];
    }
}
