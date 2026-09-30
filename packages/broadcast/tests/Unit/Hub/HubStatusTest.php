<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit\Hub;

use Hydra\Broadcast\Hub\HubHealthCheck;
use Hydra\Broadcast\Hub\HubReport;
use Hydra\Broadcast\Testing\FakeHubStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** What the hub says about itself, and how System Health reads it. */
#[CoversClass(HubReport::class)]
#[CoversClass(HubHealthCheck::class)]
#[CoversClass(FakeHubStatus::class)]
final class HubStatusTest extends TestCase
{
    public function test_a_report_round_trips(): void
    {
        $report = new HubReport(pid: 12, startedAt: 1000, connections: 3, subscribed: true, writtenAt: 1010);

        $this->assertEquals($report, HubReport::fromJson($report->toJson()));
        $this->assertSame('{"pid":12,"started_at":1000,"connections":3,"redis":"subscribed","written_at":1010}', $report->toJson());
    }

    public function test_reconnecting_is_written_out(): void
    {
        $report = new HubReport(1, 1, 0, false, 1);

        $this->assertStringContainsString('"redis":"reconnecting"', $report->toJson());
        $this->assertFalse(HubReport::fromJson($report->toJson())?->subscribed);
    }

    /** @return iterable<string, array{string}> */
    public static function malformed(): iterable
    {
        yield 'not json' => ['{'];
        yield 'a list' => ['[1,2]'];
        yield 'a missing field' => ['{"pid":1,"started_at":1,"connections":0,"redis":"subscribed"}'];
        yield 'a string count' => ['{"pid":1,"started_at":1,"connections":"0","redis":"subscribed","written_at":1}'];
        yield 'an unknown redis state' => ['{"pid":1,"started_at":1,"connections":0,"redis":"maybe","written_at":1}'];
    }

    #[DataProvider('malformed')]
    public function test_anything_else_reads_as_null(string $json): void
    {
        $this->assertNull(HubReport::fromJson($json));
    }

    public function test_the_health_check_passes_while_the_hub_is_running_and_subscribed(): void
    {
        $status = new FakeHubStatus;
        $status->publish(new HubReport(1, 1, 0, true, 1), 30);

        $check = new HubHealthCheck($status);
        $check->check();

        $this->assertSame('sse', $check->name());
    }

    public function test_the_health_check_fails_when_the_hub_is_not_running(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The SSE hub is not running.');

        (new HubHealthCheck(new FakeHubStatus))->check();
    }

    public function test_the_health_check_fails_while_the_hub_has_lost_redis(): void
    {
        $status = new FakeHubStatus;
        $status->publish(new HubReport(1, 1, 0, false, 1), 30);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The SSE hub has lost its Redis subscription.');

        (new HubHealthCheck($status))->check();
    }

    public function test_the_fake_remembers_the_ttl_and_clears(): void
    {
        $status = new FakeHubStatus;
        $status->publish(new HubReport(1, 1, 0, true, 1), 30);

        $this->assertSame(30, $status->ttl);
        $status->clear();
        $this->assertNull($status->read());
    }
}
