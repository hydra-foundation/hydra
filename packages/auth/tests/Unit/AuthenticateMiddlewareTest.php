<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The middleware's only decision: an authenticated request reaches the handler,
 * an unauthenticated one is rejected with a 401 before the handler is touched.
 */
#[CoversClass(AuthenticateMiddleware::class)]
final class AuthenticateMiddlewareTest extends TestCase
{
    public function test_authenticated_request_reaches_the_handler(): void
    {
        $middleware = new AuthenticateMiddleware(FakeGuard::signedInAs(new FakeUser));
        $handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));

        $middleware->process($this->request(), $handler);

        $handler->assertHandled();
    }

    public function test_unauthenticated_request_is_rejected_before_the_handler(): void
    {
        $middleware = new AuthenticateMiddleware(FakeGuard::guest());
        $handler = FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));

        $this->expectException(AuthenticationException::class);

        try {
            $middleware->process($this->request(), $handler);
        } finally {
            $handler->assertNotHandled();
        }
    }

    public function test_rejection_carries_a_401_status(): void
    {
        $middleware = new AuthenticateMiddleware(FakeGuard::guest());

        try {
            $middleware->process($this->request(), FakeHandler::respondingWith((new Psr17Factory)->createResponse(200)));
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->status());
        }
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/dashboard');
    }
}
