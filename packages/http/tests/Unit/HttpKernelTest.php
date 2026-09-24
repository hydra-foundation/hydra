<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Testing\FakeExceptionReporter;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\HttpKernel;
use Hydra\Http\Testing\FakeHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

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

    public function test_a_throwable_outside_the_boundary_is_reported(): void
    {
        $reporter = new FakeExceptionReporter;
        $e = new \RuntimeException('boom outside the error boundary');

        [$body] = $this->panic($e, $reporter);

        $this->assertSame('Internal Server Error', $body);
        $this->assertSame([['exception' => $e, 'context' => []]], $reporter->reports());
    }

    public function test_a_reporter_that_throws_still_leaves_the_500_and_says_so(): void
    {
        $reporter = new class implements ExceptionReporterInterface {
            public function report(Throwable $e, array $context = []): void
            {
                throw new \LogicException('tracker down');
            }
        };

        [$body, $logged] = $this->panic(new \RuntimeException('boom'), $reporter);

        $this->assertSame('Internal Server Error', $body);
        $this->assertStringContainsString('Exception reporter failed: tracker down', $logged);
    }

    public function test_with_no_reporter_only_the_panic_line_is_logged(): void
    {
        [$body, $logged] = $this->panic(new \RuntimeException('boom'), null);

        $this->assertSame('Internal Server Error', $body);
        $this->assertStringContainsString('Uncaught RuntimeException outside the error boundary: boom', $logged);
        $this->assertStringNotContainsString('reporter', $logged);
    }

    /** @return array{string, string} the echoed body and what reached error_log() */
    private function panic(Throwable $e, ?ExceptionReporterInterface $reporter): array
    {
        $requests = $this->createStub(ServerRequestProviderInterface::class);
        $requests->method('fromGlobals')->willReturn($this->createStub(ServerRequestInterface::class));
        $log = (string) tempnam(sys_get_temp_dir(), 'hydra-panic-');
        $previousLog = ini_set('error_log', $log);

        ob_start();
        try {
            (new HttpKernel($requests, FakeHandler::throwing($e), new CapturingEmitter, $reporter))->handle();
        } finally {
            $body = (string) ob_get_clean();
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            $logged = (string) file_get_contents($log);
            unlink($log);
        }

        return [$body, $logged];
    }
}
