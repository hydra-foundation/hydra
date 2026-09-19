<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Http\Testing\Client;
use Hydra\Tests\Fixture\Fixture;
use Hydra\Tests\Fixture\Http\Middleware\LoginThrottleMiddleware;
use Hydra\Throttle\ThrottleConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The limiter through the real stack, which is the only place some of this can
 * be seen: that a refusal is rendered rather than thrown at the SAPI, that it
 * is shaped for whoever asked, and that a route can carry a budget of its own
 * rather than the one every page view spends.
 */
#[CoversNothing]
final class RateLimitFlowTest extends TestCase
{
    /** Small enough that a test spends the budget in a handful of requests. */
    private const GLOBAL_LIMIT = 3;

    private Client $http;

    protected function setUp(): void
    {
        $this->boot(new ThrottleConfig(limit: self::GLOBAL_LIMIT, window: 60));
    }

    private function boot(ThrottleConfig $throttle): void
    {
        $this->http = Fixture::boot($throttle)->http();
    }

    public function test_a_client_within_the_budget_is_served(): void
    {
        $this->http->get('/')->assertOk();
    }

    public function test_the_request_past_the_budget_is_refused_with_a_retry_after(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->http->get('/')->assertOk();
        }

        // Rendered, not thrown past the pipeline: the limiter sits inside the
        // error handler precisely so a refusal has a response to be.
        $retryAfter = (int) $this->http->get('/')->assertStatus(429)->header('Retry-After');

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function test_a_refusal_is_shaped_for_whoever_asked(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->http->get('/');
        }

        $json = $this->http->get('/', ['Accept' => 'application/json'])->assertStatus(429);

        $this->assertStringContainsString('application/json', $json->header('Content-Type'));

        // And for htmx, a fragment retargeted at the layout's error region, so
        // the element the request came from keeps what the reader was looking
        // at. The retarget is markup, not a header: htmx 4 reads no response
        // headers, so the directive travels out of band in the body.
        $this->http->htmx()->get('/')->assertStatus(429)->assertSee('hx-swap-oob="innerHTML:#app-error"');
    }

    public function test_another_client_still_has_its_own_budget(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT + 1; $i++) {
            $this->http->get('/');
        }

        $this->http->from('203.0.113.9')->get('/')->assertOk();
    }

    public function test_signing_in_is_budgeted_apart_from_reading_pages(): void
    {
        // The global budget is spent first. A login attempt must not already be
        // refused by it, and must not have spent the login budget either.
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->http->get('/');
        }

        $this->http->get('/')->assertStatus(429);

        // The per-route limiter runs inside the router, so the global one has
        // already refused this client. A fresh one is what shows the login budget.
        // The post carries its CSRF token: without one the guard refuses it ahead
        // of the router, and the route's own budget never sees it.
        $this->assertNotSame(429, $this->http->from('203.0.113.9')->post('/login')->status());
    }

    public function test_the_login_budget_is_tighter_than_the_page_budget(): void
    {
        // A global budget far from spent, so the refusal below can only be the
        // login policy's own.
        $this->boot(new ThrottleConfig(limit: 100, window: 60));

        $statuses = [];

        for ($i = 0; $i < LoginThrottleMiddleware::ATTEMPTS + 1; $i++) {
            $statuses[] = $this->http->post('/login')->status();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, LoginThrottleMiddleware::ATTEMPTS));
        $this->assertSame(429, $statuses[LoginThrottleMiddleware::ATTEMPTS]);
    }
}
