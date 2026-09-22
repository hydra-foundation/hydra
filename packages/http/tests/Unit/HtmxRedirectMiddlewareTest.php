<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\HtmxRedirectMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one place that knows a redirect has to reach htmx differently. Handlers
 * and the other middleware return a plain redirect and this converts it, so a
 * redirect added later cannot forget to. htmx 4 reads no response header, so
 * the conversion is into markup; see {@see \Hydra\Http\HtmxResponse}.
 */
#[CoversClass(HtmxRedirectMiddleware::class)]
final class HtmxRedirectMiddlewareTest extends TestCase
{
    public function test_htmx_redirect_becomes_a_directive_in_a_body_htmx_will_look_at(): void
    {
        $response = $this->process($this->htmxRequest(), $this->redirect(302, '/dashboard'));

        // Not a 204: htmx 4 lists that under noSwap and never looks at the body,
        // so the redirect would be silently dropped.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-hydra-redirect="/dashboard"', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function test_a_browser_redirect_is_left_alone(): void
    {
        $response = $this->process($this->request(), $this->redirect(302, '/dashboard'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeaderLine('Location'));
        $this->assertStringNotContainsString('data-hydra-redirect', (string) $response->getBody());
    }

    public function test_a_see_other_is_converted_too(): void
    {
        $response = $this->process($this->htmxRequest(), $this->redirect(303, '/admin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-hydra-redirect="/admin"', (string) $response->getBody());
    }

    public function test_a_normal_htmx_response_passes_through(): void
    {
        // The common case: a 200 fragment must not be mistaken for a redirect.
        $response = $this->process($this->htmxRequest(), (new Psr17Factory)->createResponse(200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('data-hydra-redirect', (string) $response->getBody());
    }

    public function test_a_not_modified_is_left_alone(): void
    {
        // 304 is 3xx but carries no Location, so converting it would invent one.
        $response = $this->process($this->htmxRequest(), (new Psr17Factory)->createResponse(304));

        $this->assertSame(304, $response->getStatusCode());
        $this->assertStringNotContainsString('data-hydra-redirect', (string) $response->getBody());
    }

    private function process(ServerRequestInterface $request, ResponseInterface $from): ResponseInterface
    {
        $factory = new Psr17Factory;

        return (new HtmxRedirectMiddleware(new Responder($factory, $factory)))
            ->process($request, $this->handler($from));
    }

    private function redirect(int $status, string $to): ResponseInterface
    {
        return (new Psr17Factory)->createResponse($status)->withHeader('Location', $to);
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('POST', '/login');
    }

    private function htmxRequest(): ServerRequestInterface
    {
        return $this->request()->withHeader('HX-Request', 'true');
    }

    private function handler(ResponseInterface $response): FakeHandler
    {
        return FakeHandler::respondingWith($response);
    }
}
