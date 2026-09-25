<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\ApiToken;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\AuthenticateBearerMiddleware;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Auth\RequestGuard;
use Hydra\Auth\Testing\ArrayApiTokenStore;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(AuthenticateBearerMiddleware::class)]
#[CoversClass(AuthenticationException::class)]
final class AuthenticateBearerMiddlewareTest extends TestCase
{
    private FrozenClock $clock;
    private ApiTokens $tokens;
    private RequestGuard $guard;
    private AuthenticateBearerMiddleware $middleware;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-09-25 10:00:00');
        $this->tokens = new ApiTokens(
            new ArrayApiTokenStore,
            (new ArrayUserProvider)->add('grace', new FakeUser('grace')),
            $this->clock,
        );
        $this->guard = new RequestGuard(new FakeGuard(new FakeUser('ada')));
        $this->middleware = new AuthenticateBearerMiddleware($this->guard, $this->tokens);
    }

    private function request(?string $authorization = null): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/api/v1/me');

        return $authorization === null ? $request : $request->withHeader('Authorization', $authorization);
    }

    private function handler(): FakeHandler
    {
        return FakeHandler::respondingWith((new Psr17Factory)->createResponse(200));
    }

    public function test_a_request_without_credentials_passes_through_as_a_session_request(): void
    {
        $handler = $this->handler();

        $this->middleware->process($this->request(), $handler);

        $handler->assertHandled();
        $this->assertSame('ada', $this->guard->id());
    }

    public function test_another_scheme_is_not_ours_to_judge(): void
    {
        $handler = $this->handler();

        $this->middleware->process($this->request('Basic YWRhOnNlY3JldA=='), $handler);

        $handler->assertHandled();
        $this->assertNull($this->guard->token());
    }

    public function test_a_live_token_authenticates_the_request_as_its_owner(): void
    {
        $issued = $this->tokens->issue(new FakeUser('grace'), 'CLI');
        $handler = $this->handler();

        $this->middleware->process($this->request('Bearer ' . $issued->plain), $handler);

        $handler->assertHandled();
        $this->assertSame('grace', $this->guard->id());
        $token = $handler->lastRequest()->getAttribute(ApiToken::class);
        $this->assertInstanceOf(ApiToken::class, $token);
        $this->assertEquals($issued->token->id, $token->id);
    }

    public function test_the_scheme_is_matched_without_regard_to_case(): void
    {
        $issued = $this->tokens->issue(new FakeUser('grace'), 'CLI');

        $this->middleware->process($this->request('bearer  ' . $issued->plain), $this->handler());

        $this->assertSame('grace', $this->guard->id());
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'no token' => ['Bearer'];
        yield 'blank token' => ['Bearer   '];
        yield 'malformed' => ['Bearer not-a-token'];
        yield 'unknown' => ['Bearer hyd_' . str_repeat('a', 43)];
    }

    #[DataProvider('refused')]
    public function test_a_bearer_request_that_fails_is_a_401_before_the_handler(string $authorization): void
    {
        $handler = $this->handler();

        try {
            $this->middleware->process($this->request($authorization), $handler);
            $this->fail('The request was let through.');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->status());
            $this->assertSame(['WWW-Authenticate' => 'Bearer error="invalid_token"'], $e->headers());
        }

        $handler->assertNotHandled();
        $this->assertSame('ada', $this->guard->id(), 'a refused token must leave the guard alone');
    }

    public function test_an_expired_token_is_a_401(): void
    {
        $issued = $this->tokens->issue(new FakeUser('grace'), 'CLI', $this->clock->now()->modify('+1 minute'));
        $this->clock->advance('+1 minute');

        $this->expectException(AuthenticationException::class);

        $this->middleware->process($this->request('Bearer ' . $issued->plain), $this->handler());
    }
}
