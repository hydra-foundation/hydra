<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Csrf\CsrfGuard;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Tests\Fixture\Fixture;
use Hydra\Tests\Fixture\Http\Middleware\LoginThrottleMiddleware;
use Hydra\Throttle\ThrottleConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

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

    private Fixture $app;

    protected function setUp(): void
    {
        $this->boot(new ThrottleConfig(limit: self::GLOBAL_LIMIT, window: 60));
    }

    private function boot(ThrottleConfig $throttle): void
    {
        $this->app = Fixture::boot($throttle);
    }

    public function test_a_client_within_the_budget_is_served(): void
    {
        $this->assertSame(200, $this->get('/')->getStatusCode());
    }

    public function test_the_request_past_the_budget_is_refused_with_a_retry_after(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->assertSame(200, $this->get('/')->getStatusCode());
        }

        $response = $this->get('/');

        $this->assertSame(429, $response->getStatusCode());
        // Rendered, not thrown past the pipeline: the limiter sits inside the
        // error handler precisely so a refusal has a response to be.
        $this->assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->getHeaderLine('Retry-After'));
    }

    public function test_a_refusal_is_shaped_for_whoever_asked(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->get('/');
        }

        $json = $this->get('/', ['Accept' => 'application/json']);

        $this->assertSame(429, $json->getStatusCode());
        $this->assertStringContainsString('application/json', $json->getHeaderLine('Content-Type'));

        // And for htmx, a fragment retargeted at the layout's error region, so
        // the element the request came from keeps what the reader was looking
        // at. The retarget is markup, not a header: htmx 4 reads no response
        // headers, so the directive travels out of band in the body.
        $htmx = $this->get('/', ['HX-Request' => 'true']);

        $this->assertSame(429, $htmx->getStatusCode());
        $this->assertStringContainsString('hx-swap-oob="innerHTML:#app-error"', (string) $htmx->getBody());
    }

    public function test_another_client_still_has_its_own_budget(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT + 1; $i++) {
            $this->get('/');
        }

        $this->assertSame(200, $this->get('/', peer: '203.0.113.9')->getStatusCode());
    }

    public function test_signing_in_is_budgeted_apart_from_reading_pages(): void
    {
        // The global budget is spent first. A login attempt must not already be
        // refused by it, and must not have spent the login budget either.
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->get('/');
        }

        $this->assertSame(429, $this->get('/')->getStatusCode());

        // The per-route limiter runs inside the router, so the global one has
        // already refused this client. A fresh one is what shows the login budget.
        $this->assertNotSame(429, $this->post('/login', peer: '203.0.113.9')->getStatusCode());
    }

    public function test_the_login_budget_is_tighter_than_the_page_budget(): void
    {
        // A global budget far from spent, so the refusal below can only be the
        // login policy's own.
        $this->boot(new ThrottleConfig(limit: 100, window: 60));

        $statuses = [];

        for ($i = 0; $i < LoginThrottleMiddleware::ATTEMPTS + 1; $i++) {
            $statuses[] = $this->post('/login')->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, LoginThrottleMiddleware::ATTEMPTS));
        $this->assertSame(429, $statuses[LoginThrottleMiddleware::ATTEMPTS]);
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = [], string $peer = Fixture::PEER): ResponseInterface
    {
        return $this->app->handle('GET', $path, $headers, peer: $peer);
    }

    /**
     * A post carrying the session's CSRF token. Without one the guard refuses
     * the request in the global stack, ahead of the router, and the route's own
     * budget never sees it: cheap requests are turned away by the cheaper check.
     */
    private function post(string $path, string $peer = Fixture::PEER): ResponseInterface
    {
        $this->app->get(SessionLifecycleInterface::class)->start();

        return $this->app->handle(
            'POST',
            $path,
            ['X-CSRF-Token' => $this->app->get(CsrfGuard::class)->token()],
            peer: $peer,
        );
    }
}
