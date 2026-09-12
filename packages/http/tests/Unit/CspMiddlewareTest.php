<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Csp;
use Hydra\Http\CspMiddleware;
use Hydra\Http\CspNonce;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CspMiddlewareTest extends TestCase
{
    public function test_stamps_the_compiled_policy_on_the_response(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handler());

        $this->assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_names_the_nonce_the_page_was_rendered_with(): void
    {
        $nonce = new CspNonce;

        $response = $this->middleware(
            policy: (new Csp)->with('script-src', Csp::NONCE),
            nonce: $nonce,
        )->process($this->request(), $this->handler());

        $this->assertSame("script-src 'nonce-{$nonce->value()}'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_reports_instead_of_enforcing_when_asked_to(): void
    {
        $response = $this->middleware(reportOnly: true)->process($this->request(), $this->handler());

        $this->assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
    }

    public function test_stamps_nothing_when_disabled(): void
    {
        $response = $this->middleware(enabled: false)->process($this->request(), $this->handler());

        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy-Report-Only'));
    }

    public function test_leaves_a_policy_the_handler_set_for_itself(): void
    {
        $response = $this->middleware()->process(
            $this->request(),
            $this->handler(policy: "default-src 'none'"),
        );

        $this->assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function test_preserves_the_inner_responses_status_and_body(): void
    {
        $response = $this->middleware()->process(
            $this->request(),
            $this->handler(status: 404, body: 'gone'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('gone', (string) $response->getBody());
    }

    private function middleware(
        ?Csp $policy = null,
        ?CspNonce $nonce = null,
        bool $enabled = true,
        bool $reportOnly = false,
    ): CspMiddleware {
        return new CspMiddleware(
            $policy ?? (new Csp)->with('default-src', "'self'"),
            $nonce ?? new CspNonce,
            $enabled,
            $reportOnly,
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/');
    }

    private function handler(int $status = 200, string $body = '', ?string $policy = null): RequestHandlerInterface
    {
        return new class ($status, $body, $policy) implements RequestHandlerInterface {
            public function __construct(private int $status, private string $body, private ?string $policy) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $response = $factory->createResponse($this->status)
                    ->withBody($factory->createStream($this->body));

                return $this->policy === null
                    ? $response
                    : $response->withHeader('Content-Security-Policy', $this->policy);
            }
        };
    }
}
