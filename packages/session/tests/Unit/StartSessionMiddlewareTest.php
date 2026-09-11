<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\StartSessionMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class StartSessionMiddlewareTest extends TestCase
{
    public function test_it_starts_before_handling_and_saves_after(): void
    {
        $session = new RecordingSession;
        $response = $this->createStub(ResponseInterface::class);
        $handler = new RecordingHandler($session, $response);

        $middleware = new StartSessionMiddleware($session);
        $returned = $middleware->process($this->request(), $handler);

        $this->assertSame($response, $returned);
        // start() must run before the handler, save() after — proving the
        // session is open for the controller and closed once the request is done.
        $this->assertSame(['start', 'handle', 'save'], $session->calls);
    }

    public function test_it_saves_even_when_the_handler_throws(): void
    {
        $session = new RecordingSession;
        $handler = new ThrowingHandler($session);

        $middleware = new StartSessionMiddleware($session);

        try {
            $middleware->process($this->request(), $handler);
            $this->fail('Expected the handler exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        // The session is still saved/closed on the way out, then the exception
        // propagates unchanged to the outer error handler.
        $this->assertSame(['start', 'handle', 'save'], $session->calls);
    }

    private function request(): ServerRequestInterface
    {
        return $this->createStub(ServerRequestInterface::class);
    }
}

/** Records the order of lifecycle calls. */
final class RecordingSession implements SessionLifecycleInterface
{
    /** @var list<string> */
    public array $calls = [];

    public function start(): void
    {
        $this->calls[] = 'start';
    }

    public function save(): void
    {
        $this->calls[] = 'save';
    }
}

final class RecordingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RecordingSession $session,
        private readonly ResponseInterface $response,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->calls[] = 'handle';
        return $this->response;
    }
}

final class ThrowingHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RecordingSession $session) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->calls[] = 'handle';
        throw new RuntimeException('boom');
    }
}
