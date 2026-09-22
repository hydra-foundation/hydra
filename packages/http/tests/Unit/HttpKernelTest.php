<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\HttpKernel;
use Hydra\Http\Testing\FakeHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Records the response it was asked to emit. */
final class CapturingEmitter implements EmitterInterface
{
    public ?ResponseInterface $emitted = null;

    public function emit(ResponseInterface $response): void
    {
        $this->emitted = $response;
    }
}

#[CoversClass(HttpKernel::class)]
final class HttpKernelTest extends TestCase
{
    public function test_handle_captures_request_runs_handler_and_emits_response(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $response = $this->createStub(ResponseInterface::class);

        $requests = $this->createStub(ServerRequestProviderInterface::class);
        $requests->method('fromGlobals')->willReturn($request);

        // The application handler must receive exactly the captured request.
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($response);

        $emitter = new CapturingEmitter;

        (new HttpKernel($requests, $handler, $emitter))->handle();

        $this->assertSame($response, $emitter->emitted, 'the handler response is what gets emitted');
    }

    public function test_handle_contains_throwables_from_the_handler_as_a_last_resort(): void
    {
        $requests = $this->createStub(ServerRequestProviderInterface::class);
        $requests->method('fromGlobals')->willReturn($this->createStub(ServerRequestInterface::class));

        // Simulates a throwable escaping the whole pipeline: an outer middleware
        // or the lazy container resolution blowing up before the error handler
        // middleware could catch it.
        $handler = FakeHandler::throwing(new \RuntimeException('boom outside the error boundary'));

        $emitter = new CapturingEmitter;

        // Route error_log() into the void for this test so the deliberate
        // panic line doesn't pollute the test runner's output.
        $previousLog = ini_set('error_log', '/dev/null');

        // The catch block echoes a plain-text body directly (it bypasses
        // PSR-7 on purpose); buffer it so we can assert on it without it
        // leaking into PHPUnit's output.
        ob_start();
        try {
            (new HttpKernel($requests, $handler, $emitter))->handle();
        } finally {
            $body = ob_get_clean();
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        // Reaching here at all is the core assertion: no throwable escaped.
        $this->assertNull($emitter->emitted, 'the last-resort path must not go through the emitter');
        $this->assertSame('Internal Server Error', $body, 'minimal body, never exception details');
    }
}
