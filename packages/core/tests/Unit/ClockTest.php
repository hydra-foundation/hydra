<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Hydra\Core\Clock\ClockServiceProvider;
use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(SystemClock::class)]
#[CoversClass(ClockServiceProvider::class)]
#[CoversClass(FrozenClock::class)]
final class ClockTest extends TestCase
{
    public function test_the_system_clock_reads_the_wall_clock(): void
    {
        $before = new DateTimeImmutable;
        $now = (new SystemClock)->now();
        $after = new DateTimeImmutable;

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function test_the_system_clock_reports_in_the_zone_it_was_given(): void
    {
        $now = (new SystemClock(new DateTimeZone('Pacific/Auckland')))->now();

        $this->assertSame('Pacific/Auckland', $now->getTimezone()->getName());
    }

    public function test_the_provider_binds_one_system_clock(): void
    {
        $container = $this->container();
        (new ClockServiceProvider)->register($container);

        $clock = $container->get(ClockInterface::class);

        $this->assertInstanceOf(SystemClock::class, $clock);
        $this->assertSame($clock, $container->get(ClockInterface::class));
    }

    public function test_a_frozen_clock_stands_still(): void
    {
        $clock = new FrozenClock;

        $this->assertEquals(new DateTimeImmutable(FrozenClock::DEFAULT), $clock->now());
        $this->assertSame($clock->now(), $clock->now());
    }

    public function test_a_frozen_clock_starts_where_it_is_told(): void
    {
        $this->assertSame('2030-06-15 09:30:00', (new FrozenClock('2030-06-15 09:30:00'))->now()->format('Y-m-d H:i:s'));

        $moment = new DateTimeImmutable('1999-12-31 23:59:59');
        $this->assertSame($moment, (new FrozenClock($moment))->now());
    }

    public function test_set_moves_it_anywhere(): void
    {
        $clock = new FrozenClock;
        $clock->set('2020-02-29 00:00:00');

        $this->assertSame('2020-02-29', $clock->now()->format('Y-m-d'));
    }

    public function test_advance_takes_a_relative_format_or_an_interval(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00');

        $clock->advance('+90 seconds');
        $this->assertSame('12:01:30', $clock->now()->format('H:i:s'));

        $clock->advance(new DateInterval('PT1H'));
        $this->assertSame('13:01:30', $clock->now()->format('H:i:s'));

        $clock->advance('-1 day');
        $this->assertSame('2025-12-31 13:01:30', $clock->now()->format('Y-m-d H:i:s'));
    }

    public function test_one_clock_moves_for_everyone_holding_it(): void
    {
        // The reason it is mutable: a service resolved before the test moved
        // time has to see the new time, not the one it was built at.
        $clock = new FrozenClock;
        $holder = new class ($clock) {
            public function __construct(public readonly ClockInterface $clock) {}
        };

        $clock->advance('+1 hour');

        $this->assertEquals($clock->now(), $holder->clock->now());
    }

    public function test_an_unreadable_advance_names_itself(): void
    {
        $clock = new FrozenClock;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot advance the clock by "a fortnight or so".');

        $clock->advance('a fortnight or so');
    }

    private function container(): ContainerInterface
    {
        return new FakeContainer;
    }
}
