<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\SecurityHeadersMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The security headers are stamped on the response, and nothing the inner
 * handler produced is disturbed in the process.
 */
#[CoversClass(SecurityHeadersMiddleware::class)]
final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function test_stamps_the_security_headers_on_the_response(): void
    {
        $response = (new SecurityHeadersMiddleware)->process($this->request(), $this->handler());

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    /**
     * The set is an argument, so an application can send the one that suits
     * what else it sends. Nothing here may assume the defaults.
     */
    public function test_stamps_the_set_it_was_given_instead_of_the_defaults(): void
    {
        $response = (new SecurityHeadersMiddleware(['Referrer-Policy' => 'no-referrer']))
            ->process($this->request(), $this->handler());

        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        $this->assertFalse($response->hasHeader('X-Content-Type-Options'));
    }

    /**
     * A policy with frame-ancestors already says what X-Frame-Options says, so
     * the set for an application that sends one leaves the header off.
     */
    public function test_the_csp_set_leaves_out_the_header_the_policy_supersedes(): void
    {
        $response = (new SecurityHeadersMiddleware(SecurityHeadersMiddleware::WITH_CSP))
            ->process($this->request(), $this->handler());

        $this->assertFalse($response->hasHeader('X-Frame-Options'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    /** An empty set is a set, not an omission: it must not fall back to the defaults. */
    public function test_an_empty_set_stamps_nothing(): void
    {
        $response = (new SecurityHeadersMiddleware([]))->process($this->request(), $this->handler());

        $this->assertFalse($response->hasHeader('X-Content-Type-Options'));
        $this->assertFalse($response->hasHeader('X-Frame-Options'));
        $this->assertFalse($response->hasHeader('Referrer-Policy'));
    }

    public function test_preserves_the_inner_responses_status_and_body(): void
    {
        $response = (new SecurityHeadersMiddleware)->process(
            $this->request(),
            $this->handler(status: 404, body: 'gone'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('gone', (string) $response->getBody());
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/');
    }

    private function handler(int $status = 200, string $body = ''): RequestHandlerInterface
    {
        return new class ($status, $body) implements RequestHandlerInterface {
            public function __construct(private int $status, private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                return $factory->createResponse($this->status)
                    ->withBody($factory->createStream($this->body));
            }
        };
    }
}
