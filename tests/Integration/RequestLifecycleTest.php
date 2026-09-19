<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Core\Contracts\KernelInterface;
use Hydra\Http\HttpKernel;
use Hydra\Http\Testing\Client;
use Hydra\Tests\Fixture\Config\CspConfig;
use Hydra\Tests\Fixture\Fixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The composition root end to end: a real container, the real provider stack
 * and actual PSR-7 requests. Unit tests mock the seams; this one proves they
 * connect, so a wrong binding id or a circular get() shows up here rather than
 * at curl-time.
 */
#[CoversNothing]
final class RequestLifecycleTest extends TestCase
{
    private Fixture $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = Fixture::boot();
        $this->http = $this->app->http();
    }

    public function test_kernel_graph_resolves(): void
    {
        // Resolving the kernel constructs the entire object graph (request
        // provider, pipeline, router, emitter, logger), so a wiring typo fails
        // here rather than on the first request.
        $this->assertInstanceOf(HttpKernel::class, $this->app->get(KernelInterface::class));
    }

    public function test_root_route_returns_a_page(): void
    {
        $response = $this->http->get('/')->assertOk()->assertSee('Welcome to Hydra')->assertSee('<!doctype html>');

        $this->assertStringContainsString('text/html', $response->header('Content-Type'));
    }

    public function test_unknown_path_renders_a_404(): void
    {
        // The Router throws NotFoundException; the pipeline's
        // ErrorHandlerMiddleware catches it and renders a response, so handle()
        // never throws to the SAPI.
        $this->assertSame('Not Found', $this->http->get('/does-not-exist')->assertStatus(404)->body());
    }

    public function test_head_request_is_served_by_the_get_route(): void
    {
        $this->http->send($this->http->request('HEAD', '/'))->assertOk();
    }

    public function test_every_response_carries_the_content_security_policy(): void
    {
        $policy = $this->http->get('/')->header('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("img-src 'self' data:", $policy);
        $this->assertStringContainsString("font-src 'self' https://fonts.gstatic.com", $policy);
        $this->assertStringContainsString("style-src 'self' https://fonts.googleapis.com", $policy);
    }

    public function test_the_policys_nonce_is_the_one_the_page_was_rendered_with(): void
    {
        // The header and the markup are written by different parts of the
        // pipeline; a page whose nonce does not match its own policy would load
        // with every inline script blocked.
        $response = $this->http->get('/');

        $this->assertSame(
            1,
            preg_match(
                "/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/",
                $response->header('Content-Security-Policy'),
                $header,
            ),
        );
        $response->assertSee(sprintf('<script nonce="%s"', $header[1]));
    }

    /**
     * hx-csp recovers a swapped fragment's nonce from the policy header on the
     * response that carried it, so whichever header is sent has to name the
     * nonce the markup was stamped with. Report-only is the mode a policy is
     * rolled out in: if only the enforcing header ever named the nonce, every
     * swap during that rollout would arrive unrecognised and be stripped.
     */
    public function test_report_only_sends_the_nonce_under_the_report_only_header(): void
    {
        $this->app->container()->instance(CspConfig::class, new CspConfig(reportOnly: true));

        $response = $this->http->get('/')->assertHeaderMissing('Content-Security-Policy');

        $this->assertSame(
            1,
            preg_match(
                "/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/",
                $response->header('Content-Security-Policy-Report-Only'),
                $header,
            ),
        );
        $response->assertSee(sprintf('<script nonce="%s"', $header[1]));
    }

    /**
     * Turning CSP off has to turn the htmx gate off with it. The gate is armed
     * by naming the extension in htmx-config and strips any element whose nonce
     * it cannot match against the response's policy, so leaving it armed with
     * no policy to read would break every swap on a page meant to be running
     * without a policy at all.
     */
    public function test_disabling_the_policy_disarms_the_htmx_gate(): void
    {
        $this->app->container()->instance(CspConfig::class, new CspConfig(enabled: false));

        $this->http->get('/')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only')
            ->assertDontSee('extensions:"hx-csp"');
    }

    public function test_an_enforced_policy_arms_the_htmx_gate(): void
    {
        $this->http->get('/')->assertSee('extensions:"hx-csp"');
    }

    public function test_one_container_holds_one_nonce(): void
    {
        // What makes the header and the page agree: every reader resolves the
        // same CspNonce out of the container, and a real SAPI builds one
        // container per request. A second instance would mint a second token
        // and leave the policy naming a nonce the page never carried.
        $first = $this->http->get('/')->header('Content-Security-Policy');
        $second = $this->http->get('/')->header('Content-Security-Policy');

        $this->assertSame($first, $second);
    }
}
