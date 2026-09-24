<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Testing\FakeHealthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeHealthCheck::class)]
final class FakeHealthCheckTest extends TestCase
{
    public function test_a_passing_check_returns_and_counts_its_runs(): void
    {
        $check = FakeHealthCheck::passing('database');

        $check->check();
        $check->check();

        $this->assertSame('database', $check->name());
        $this->assertSame(2, $check->runs());
    }

    public function test_a_failing_check_throws_its_reason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused');

        FakeHealthCheck::failing('cache', 'refused')->check();
    }

    public function test_the_answer_can_change_between_probes(): void
    {
        $check = FakeHealthCheck::failing('cache');
        $check->recover();
        $check->check();

        $check->fail('gone');

        $this->expectExceptionMessage('gone');
        $check->check();
    }
}
