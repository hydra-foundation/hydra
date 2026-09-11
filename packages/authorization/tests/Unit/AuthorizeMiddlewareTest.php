<?php

declare(strict_types=1);

namespace Hydra\Authorization\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Authorization\AuthorizeMiddleware;
use Hydra\Authorization\Contracts\AbilityInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The middleware is driven against a spy gate (the one seam it sits on) and a
 * spy handler standing in for the rest of the pipeline. The concrete subclass
 * fixes the ability, exactly as an app's would.
 */
final class AuthorizeMiddlewareTest extends TestCase
{
    public function test_lets_the_request_through_when_the_gate_allows(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $handler = new SpyHandler($response);
        $gate = new SpyGate;

        $result = (new RequireWidgetAccess($gate))->process($this->request(), $handler);

        $this->assertTrue($handler->called);
        $this->assertSame($response, $result);
    }

    public function test_enforces_the_ability_the_subclass_declares(): void
    {
        $gate = new SpyGate;

        (new RequireWidgetAccess($gate))->process($this->request(), new SpyHandler($this->createStub(ResponseInterface::class)));

        $this->assertSame(AccessWidget::class, $gate->authorized);
    }

    public function test_propagates_the_403_and_stops_the_pipeline_when_denied(): void
    {
        $handler = new SpyHandler($this->createStub(ResponseInterface::class));
        $gate = new SpyGate(deny: true);

        try {
            (new RequireWidgetAccess($gate))->process($this->request(), $handler);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->status());
        }

        // The controller (and everything inward) never runs on a denied request.
        $this->assertFalse($handler->called);
    }

    private function request(): ServerRequestInterface
    {
        return $this->createStub(ServerRequestInterface::class);
    }
}

/** A concrete gate-enforcing middleware, as an app would write. */
final class RequireWidgetAccess extends AuthorizeMiddleware
{
    protected function ability(): string
    {
        return AccessWidget::class;
    }
}

/** The fixed ability the middleware above enforces. */
final class AccessWidget implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return true;
    }
}

/** Records the ability it was asked to enforce; throws like the real gate when denying. */
final class SpyGate implements GateInterface
{
    public ?string $authorized = null;

    public function __construct(private readonly bool $deny = false) {}

    public function allows(string $ability, mixed $subject = null): bool
    {
        return !$this->deny;
    }

    public function denies(string $ability, mixed $subject = null): bool
    {
        return $this->deny;
    }

    public function authorize(string $ability, mixed $subject = null): void
    {
        $this->authorized = $ability;

        if ($this->deny) {
            throw new AuthorizationException;
        }
    }
}

/** Marks whether the rest of the pipeline was reached. */
final class SpyHandler implements RequestHandlerInterface
{
    public bool $called = false;

    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;

        return $this->response;
    }
}
