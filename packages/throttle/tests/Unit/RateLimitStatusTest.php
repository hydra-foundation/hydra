<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Throttle\RateLimitStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the limiter reports, and what the response headers are built from. The
 * one piece of arithmetic here is the one a client reads: a negative remaining
 * would be sent as X-RateLimit-Remaining: -3, which no client treats as zero.
 */
#[CoversClass(RateLimitStatus::class)]
final class RateLimitStatusTest extends TestCase
{
    public function test_it_reports_what_is_left_of_the_budget(): void
    {
        $status = new RateLimitStatus(allowed: true, limit: 60, used: 11, retryAfter: 0);

        $this->assertSame(49, $status->remaining());
    }

    public function test_a_budget_spent_exactly_leaves_nothing(): void
    {
        $status = new RateLimitStatus(allowed: true, limit: 60, used: 60, retryAfter: 0);

        $this->assertSame(0, $status->remaining());
    }

    public function test_overspending_reads_as_nothing_left_rather_than_a_debt(): void
    {
        // A burst can be counted past the limit before the refusal lands, and
        // the header that carries this has no meaning below zero.
        $status = new RateLimitStatus(allowed: false, limit: 60, used: 63, retryAfter: 30);

        $this->assertSame(0, $status->remaining());
    }

    public function test_it_carries_the_verdict_and_the_wait_as_given(): void
    {
        $status = new RateLimitStatus(allowed: false, limit: 5, used: 6, retryAfter: 42);

        $this->assertFalse($status->allowed);
        $this->assertSame(5, $status->limit);
        $this->assertSame(6, $status->used);
        $this->assertSame(42, $status->retryAfter);
    }
}
