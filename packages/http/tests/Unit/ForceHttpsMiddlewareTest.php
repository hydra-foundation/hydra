<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ClientIpResolver;
use Hydra\Http\ForceHttpsMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\TrustedProxies;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ForceHttpsMiddlewareTest extends TestCase
{
    public function test_passes_through_untouched_when_disabled(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(enabled: false)
            ->process($this->request('http://hydra.test/login'), $handler);

        $this->assertSame(1, $handler->calls, 'handler should run');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function test_redirects_insecure_request_to_https_with_a_301(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(enabled: true)
            ->process($this->request('http://hydra.test/login?next=/x'), $handler);

        $this->assertSame(0, $handler->calls, 'handler must not run for a redirect');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://hydra.test/login?next=/x', $response->getHeaderLine('Location'));
    }

    public function test_redirect_drops_an_explicit_http_port(): void
    {
        $response = $this->middleware(enabled: true)
            ->process($this->request('http://hydra.test:80/login'), $this->handler());

        $this->assertSame('https://hydra.test/login', $response->getHeaderLine('Location'));
    }

    public function test_secure_request_proceeds_and_gets_hsts(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(enabled: true)
            ->process($this->request('https://hydra.test/login'), $handler);

        $this->assertSame(1, $handler->calls);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_trusts_x_forwarded_proto_only_when_the_app_opts_in(): void
    {
        $handler = $this->handler();
        $request = $this->request('http://hydra.test/login')->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->middleware(enabled: true, trustForwardedProto: true)
            ->process($request, $handler);

        $this->assertSame(1, $handler->calls, 'forwarded https should count as secure');
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_ignores_spoofed_x_forwarded_proto_when_trust_is_off(): void
    {
        $handler = $this->handler();
        // A client hitting the app directly can send any header it likes; with
        // no declared proxy this must still be treated as an insecure request.
        $request = $this->request('http://hydra.test/login')->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->middleware(enabled: true)->process($request, $handler);

        $this->assertSame(0, $handler->calls, 'spoofed header must not bypass the redirect');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://hydra.test/login', $response->getHeaderLine('Location'));
    }

    public function test_the_forwarded_scheme_counts_only_from_a_declared_proxy(): void
    {
        $handler = $this->handler();
        $request = $this->request('http://hydra.test/login', peer: '10.0.0.1')
            ->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->middleware(
            enabled: true,
            trustForwardedProto: true,
            clients: new ClientIpResolver(new TrustedProxies(['10.0.0.0/8'])),
        )->process($request, $handler);

        $this->assertSame(1, $handler->calls);
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_the_forwarded_scheme_is_refused_from_a_peer_that_is_not_the_proxy(): void
    {
        // Opting into the header is not the same as opting into anyone who
        // sends it: a request that skipped the proxy is still plain http.
        $handler = $this->handler();
        $request = $this->request('http://hydra.test/login', peer: '198.51.100.7')
            ->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->middleware(
            enabled: true,
            trustForwardedProto: true,
            clients: new ClientIpResolver(new TrustedProxies(['10.0.0.0/8'])),
        )->process($request, $handler);

        $this->assertSame(0, $handler->calls, 'a direct client must not borrow the proxy\'s scheme');
        $this->assertSame(301, $response->getStatusCode());
    }

    private function middleware(
        bool $enabled,
        bool $trustForwardedProto = false,
        ?ClientIpResolver $clients = null,
    ): ForceHttpsMiddleware {
        $factory = new Psr17Factory;

        return new ForceHttpsMiddleware(
            $enabled,
            new Responder($factory, $factory),
            $trustForwardedProto,
            $clients,
        );
    }

    private function request(string $uri, ?string $peer = null): ServerRequestInterface
    {
        $server = $peer === null ? [] : ['REMOTE_ADDR' => $peer];

        return (new Psr17Factory)->createServerRequest('GET', $uri, $server);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return (new Psr17Factory)->createResponse(200);
            }
        };
    }
}
