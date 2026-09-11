<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Auth\Contracts\GuardInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthenticateMiddlewareTest extends TestCase
{
    public function test_authenticated_request_reaches_the_handler(): void
    {
        $middleware = new AuthenticateMiddleware(new FakeGuard(authenticated: true));
        $handler = new RecordingHandler;

        $middleware->process($this->request(), $handler);

        $this->assertSame(1, $handler->calls);
    }

    public function test_unauthenticated_request_is_rejected_before_the_handler(): void
    {
        $middleware = new AuthenticateMiddleware(new FakeGuard(authenticated: false));
        $handler = new RecordingHandler;

        $this->expectException(AuthenticationException::class);

        try {
            $middleware->process($this->request(), $handler);
        } finally {
            $this->assertSame(0, $handler->calls);
        }
    }

    public function test_rejection_carries_a_401_status(): void
    {
        $middleware = new AuthenticateMiddleware(new FakeGuard(authenticated: false));

        try {
            $middleware->process($this->request(), new RecordingHandler);
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

/** A guard whose authentication state is fixed for the test. */
final class FakeGuard implements GuardInterface
{
    public function __construct(private readonly bool $authenticated) {}

    public function check(): bool
    {
        return $this->authenticated;
    }

    public function user(): ?AuthenticatableInterface
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    public function attempt(string $username, string $password): bool
    {
        return false;
    }

    public function login(AuthenticatableInterface $user): void {}

    public function logout(): void {}
}

final class RecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return (new Psr17Factory)->createResponse(200);
    }
}
