<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\CorsConfig;
use Hydra\Http\CorsMiddleware;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(CorsMiddleware::class)]
final class CorsMiddlewareTest extends TestCase
{
    private static function middleware(?CorsConfig $config = null): CorsMiddleware
    {
        return new CorsMiddleware($config ?? new CorsConfig(allowedOrigins: ['https://a.test']), new Psr17Factory);
    }

    private static function handler(int $status = 200): FakeHandler
    {
        return FakeHandler::respondingWith((new Psr17Factory)->createResponse($status));
    }

    /** @param array<string, string> $headers */
    private static function request(string $method, string $path, array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private static function preflight(string $origin, string $path = '/api/v1/me'): ServerRequestInterface
    {
        return self::request('OPTIONS', $path, [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization',
        ]);
    }

    private static function assertNoCorsHeaders(ResponseInterface $response): void
    {
        foreach (array_keys($response->getHeaders()) as $name) {
            self::assertStringStartsNotWith('access-control-', strtolower((string) $name));
        }
    }

    public function test_a_preflight_from_an_allowed_origin_is_answered_without_the_router(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::preflight('https://a.test'), $handler);

        $handler->assertNotHandled();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://a.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, PATCH, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Authorization, Content-Type, Accept', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_a_preflight_from_another_origin_is_answered_with_nothing_to_allow_it(): void
    {
        $handler = self::handler();

        $response = self::middleware()->process(self::preflight('https://b.test'), $handler);

        $handler->assertNotHandled();
        $this->assertSame(204, $response->getStatusCode());
        self::assertNoCorsHeaders($response);
    }

    public function test_a_request_from_an_allowed_origin_may_read_the_response(): void
    {
        $response = self::middleware()->process(
            self::request('GET', '/api/v1/me', ['Origin' => 'https://a.test']),
            self::handler(),
        );

        $this->assertSame('https://a.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('X-Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'), 'only a preflight lists methods');
    }

    public function test_an_error_response_is_readable_too(): void
    {
        $response = self::middleware()->process(
            self::request('GET', '/api/v1/me', ['Origin' => 'https://a.test']),
            self::handler(401),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://a.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_a_request_from_another_origin_gets_no_allowance(): void
    {
        $response = self::middleware()->process(
            self::request('GET', '/api/v1/me', ['Origin' => 'https://b.test']),
            self::handler(),
        );

        self::assertNoCorsHeaders($response);
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_vary_is_appended_to_what_the_response_already_varies_on(): void
    {
        $handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200)->withHeader('Vary', 'Accept'));

        $response = self::middleware()->process(self::request('GET', '/api/v1/me'), $handler);

        $this->assertSame('Accept, Origin', $response->getHeaderLine('Vary'));
    }

    public function test_a_path_outside_the_cors_paths_is_left_alone(): void
    {
        $response = self::middleware()->process(
            self::request('GET', '/admin', ['Origin' => 'https://a.test']),
            self::handler(),
        );

        self::assertNoCorsHeaders($response);
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function test_an_options_request_outside_the_cors_paths_reaches_the_router(): void
    {
        $handler = self::handler(405);

        self::middleware()->process(self::preflight('https://a.test', '/admin'), $handler);

        $handler->assertHandled();
    }

    public function test_with_no_origins_configured_nothing_changes(): void
    {
        $handler = self::handler(405);

        $response = self::middleware(new CorsConfig)->process(self::preflight('https://a.test'), $handler);

        $handler->assertHandled();
        $this->assertSame(405, $response->getStatusCode());
        self::assertNoCorsHeaders($response);
        $this->assertFalse($response->hasHeader('Vary'));
    }

    public function test_a_wildcard_answers_with_a_star(): void
    {
        $response = self::middleware(new CorsConfig(allowedOrigins: ['*']))->process(
            self::request('GET', '/api/v1/me', ['Origin' => 'https://anyone.test']),
            self::handler(),
        );

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_credentials_are_never_allowed(): void
    {
        $preflight = self::middleware()->process(self::preflight('https://a.test'), self::handler());
        $actual = self::middleware()->process(
            self::request('GET', '/api/v1/me', ['Origin' => 'https://a.test']),
            self::handler(),
        );

        $this->assertFalse($preflight->hasHeader('Access-Control-Allow-Credentials'));
        $this->assertFalse($actual->hasHeader('Access-Control-Allow-Credentials'));
    }
}
