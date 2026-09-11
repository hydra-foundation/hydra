<?php

declare(strict_types=1);

namespace Hydra\Csrf\Tests\Unit;

use Hydra\Core\Security\Signer;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Exceptions\TokenMismatchException;
use Hydra\Csrf\VerifyCsrfTokenMiddleware;
use Hydra\Session\Stores\ArraySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class VerifyCsrfTokenMiddlewareTest extends TestCase
{
    public function test_safe_methods_pass_through_without_a_token(): void
    {
        $guard = $this->guard();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $middleware->process($this->request($method, '/x'), $handler);
        }

        // No side effects, so no token required: the handler ran each time.
        $this->assertSame(3, $handler->calls);
    }

    public function test_unsafe_method_with_a_valid_token_in_the_header_passes(): void
    {
        $guard = $this->guard();
        $token = $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')->withHeader(CsrfGuard::HEADER, $token);
        $middleware->process($request, $handler);

        $this->assertSame(1, $handler->calls);
    }

    public function test_unsafe_method_with_a_valid_token_in_the_form_field_passes(): void
    {
        $guard = $this->guard();
        $token = $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')->withParsedBody([CsrfGuard::FIELD => $token]);
        $middleware->process($request, $handler);

        $this->assertSame(1, $handler->calls);
    }

    public function test_unsafe_method_without_a_token_is_rejected(): void
    {
        $guard = $this->guard();
        $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $this->expectException(TokenMismatchException::class);

        try {
            $middleware->process($this->request('POST', '/x'), $handler);
        } finally {
            // The request was stopped before the controller ran.
            $this->assertSame(0, $handler->calls);
        }
    }

    public function test_unsafe_method_with_a_wrong_token_is_rejected(): void
    {
        $guard = $this->guard();
        $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')->withHeader(CsrfGuard::HEADER, 'wrong');

        $this->expectException(TokenMismatchException::class);
        $middleware->process($request, $handler);
    }

    public function test_rejection_carries_a_403_status(): void
    {
        $guard = $this->guard();
        $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);

        try {
            $middleware->process($this->request('POST', '/x'), new RecordingHandler);
            $this->fail('Expected a TokenMismatchException.');
        } catch (TokenMismatchException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function test_unknown_and_custom_verbs_are_guarded(): void
    {
        // The safe list is an ALLOWLIST: a verb the framework never anticipated
        // (WebDAV's PROPFIND, a proxy's PURGE, anything custom) must fail closed
        // and require a token, not slip past an unsafe-method blocklist.
        foreach (['PROPFIND', 'PURGE', 'X-CUSTOM'] as $method) {
            $guard = $this->guard();
            $guard->token();
            $middleware = new VerifyCsrfTokenMiddleware($guard);
            $handler = new RecordingHandler;

            try {
                $middleware->process($this->request($method, '/x'), $handler);
                $this->fail("Expected {$method} without a token to be rejected.");
            } catch (TokenMismatchException) {
                $this->assertSame(0, $handler->calls, "{$method} reached the handler without a token");
            }
        }
    }

    public function test_lowercase_unsafe_method_is_still_guarded(): void
    {
        // Method comparison must be case-insensitive (strtoupper is load-bearing):
        // a lowercase 'post' must not be mistaken for a non-listed safe verb —
        // and, symmetrically, a lowercase 'get' must not lose its exemption.
        $guard = $this->guard();
        $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $this->expectException(TokenMismatchException::class);

        try {
            $middleware->process($this->request('post', '/x'), $handler);
        } finally {
            $this->assertSame(0, $handler->calls);
        }
    }

    public function test_lowercase_safe_method_passes_without_a_token(): void
    {
        $guard = $this->guard();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $middleware->process($this->request('get', '/x'), $handler);

        $this->assertSame(1, $handler->calls);
    }

    public function test_every_unsafe_method_is_guarded(): void
    {
        // POST is exercised throughout; this locks the other classic mutating
        // verbs so a change to the safe allowlist can't silently exempt one.
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $guard = $this->guard();
            $token = $guard->token();
            $middleware = new VerifyCsrfTokenMiddleware($guard);

            // Without a token: rejected, handler never reached.
            $handler = new RecordingHandler;
            try {
                $middleware->process($this->request($method, '/x'), $handler);
                $this->fail("Expected {$method} without a token to be rejected.");
            } catch (TokenMismatchException) {
                $this->assertSame(0, $handler->calls, "{$method} reached the handler without a token");
            }

            // With a valid token: passes through.
            $passed = new RecordingHandler;
            $request = $this->request($method, '/x')->withHeader(CsrfGuard::HEADER, $token);
            $middleware->process($request, $passed);
            $this->assertSame(1, $passed->calls, "{$method} with a valid token was blocked");
        }
    }

    public function test_an_empty_header_falls_back_to_the_form_field(): void
    {
        // getHeaderLine() returns '' for both absent and present-but-empty, so an
        // empty header must not short-circuit the field lookup — otherwise a
        // client that sends an empty X-CSRF-Token could never use the form field.
        $guard = $this->guard();
        $token = $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')
            ->withHeader(CsrfGuard::HEADER, '')
            ->withParsedBody([CsrfGuard::FIELD => $token]);
        $middleware->process($request, $handler);

        $this->assertSame(1, $handler->calls);
    }

    public function test_non_array_parsed_body_is_treated_as_no_token(): void
    {
        // PSR-7's getParsedBody() can be null or an object; neither carries a
        // field, so the request must be rejected rather than TypeError.
        $guard = $this->guard();
        $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')->withParsedBody((object) [CsrfGuard::FIELD => 'x']);

        $this->expectException(TokenMismatchException::class);

        try {
            $middleware->process($request, $handler);
        } finally {
            $this->assertSame(0, $handler->calls);
        }
    }

    public function test_header_is_preferred_over_the_form_field(): void
    {
        // A valid header wins even when the body field is junk — the htmx path
        // (auto-header) should not be defeated by a stale field, and vice versa
        // a valid field is enough when no header is sent (covered above).
        $guard = $this->guard();
        $token = $guard->token();
        $middleware = new VerifyCsrfTokenMiddleware($guard);
        $handler = new RecordingHandler;

        $request = $this->request('POST', '/x')
            ->withHeader(CsrfGuard::HEADER, $token)
            ->withParsedBody([CsrfGuard::FIELD => 'garbage']);
        $middleware->process($request, $handler);

        $this->assertSame(1, $handler->calls);
    }

    private function request(string $method, string $path): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest($method, $path);
    }

    /** A guard over a fresh started store, signing under a fixed test key. */
    private function guard(): CsrfGuard
    {
        return new CsrfGuard(
            $this->startedStore(),
            Signer::fromHex('00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff'),
        );
    }

    /** The store as the middleware hands it to request code: already started. */
    private function startedStore(): ArraySessionStore
    {
        $store = new ArraySessionStore;
        $store->start();

        return $store;
    }
}

/** Counts how many times the inner handler was reached. */
final class RecordingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;
        return (new Psr17Factory)->createResponse(200);
    }
}
