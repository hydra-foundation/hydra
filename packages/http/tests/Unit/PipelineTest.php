<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use ArrayObject;
use Hydra\Http\Pipeline;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records when it is entered/exited and either passes through to the inner
 * handler or short-circuits with a canned response.
 */
final class RecordingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $tag,
        private readonly ArrayObject $log,
        private readonly ?ResponseInterface $shortCircuit = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->log[] = "enter:{$this->tag}";

        if ($this->shortCircuit !== null) {
            $this->log[] = "short:{$this->tag}";
            return $this->shortCircuit;
        }

        $response = $handler->handle($request);
        $this->log[] = "exit:{$this->tag}";

        return $response;
    }
}

/** The innermost handler (stands in for the router). */
final class RecordingKernel implements RequestHandlerInterface
{
    public bool $called = false;

    public function __construct(
        private readonly ResponseInterface $response,
        private readonly ArrayObject $log,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->called = true;
        $this->log[] = 'kernel';

        return $this->response;
    }
}

final class PipelineTest extends TestCase
{
    private function request(): ServerRequestInterface
    {
        return $this->createStub(ServerRequestInterface::class);
    }

    private function response(): ResponseInterface
    {
        return $this->createStub(ResponseInterface::class);
    }

    public function test_empty_queue_delegates_straight_to_kernel(): void
    {
        $log = new ArrayObject;
        $kernelResponse = $this->response();
        $kernel = new RecordingKernel($kernelResponse, $log);

        $response = (new Pipeline([], $kernel))->handle($this->request());

        $this->assertTrue($kernel->called);
        $this->assertSame($kernelResponse, $response);
        $this->assertSame(['kernel'], $log->getArrayCopy());
    }

    public function test_runs_middleware_outside_in_and_unwinds_inside_out(): void
    {
        $log = new ArrayObject;
        $kernelResponse = $this->response();
        $kernel = new RecordingKernel($kernelResponse, $log);

        $pipeline = new Pipeline([
            new RecordingMiddleware('A', $log),
            new RecordingMiddleware('B', $log),
        ], $kernel);

        $response = $pipeline->handle($this->request());

        // Request travels inward A→B→kernel; response unwinds outward B→A.
        $this->assertSame(
            ['enter:A', 'enter:B', 'kernel', 'exit:B', 'exit:A'],
            $log->getArrayCopy()
        );
        $this->assertSame($kernelResponse, $response);
    }

    public function test_short_circuit_skips_inner_middleware_and_kernel(): void
    {
        $log = new ArrayObject;
        $kernel = new RecordingKernel($this->response(), $log);
        $blocked = $this->response();

        $pipeline = new Pipeline([
            new RecordingMiddleware('A', $log),
            new RecordingMiddleware('B', $log, shortCircuit: $blocked),
            new RecordingMiddleware('C', $log), // must never run
        ], $kernel);

        $response = $pipeline->handle($this->request());

        $this->assertSame($blocked, $response);
        $this->assertFalse($kernel->called, 'kernel must not run after a short-circuit');
        $this->assertSame(
            ['enter:A', 'enter:B', 'short:B', 'exit:A'],
            $log->getArrayCopy()
        );
    }

    public function test_pipeline_is_re_entrant(): void
    {
        // Guards against mutating the queue in place: handling a second request
        // through the same Pipeline instance must replay the full chain.
        $log = new ArrayObject;
        $kernel = new RecordingKernel($this->response(), $log);

        $pipeline = new Pipeline([new RecordingMiddleware('A', $log)], $kernel);

        $pipeline->handle($this->request());
        $log->exchangeArray([]); // reset, keep same instances
        $pipeline->handle($this->request());

        $this->assertSame(['enter:A', 'kernel', 'exit:A'], $log->getArrayCopy());
    }

    public function test_generator_input_is_accepted_like_array(): void
    {
        // The constructor accepts iterable<MiddlewareInterface>, not just arrays.
        // A generator must produce the same ordering as an equivalent array.
        $log = new ArrayObject;
        $kernelResponse = $this->response();
        $kernel = new RecordingKernel($kernelResponse, $log);

        $generator = (static function () use ($log): \Generator {
            yield new RecordingMiddleware('A', $log);
            yield new RecordingMiddleware('B', $log);
        })();

        $response = (new Pipeline($generator, $kernel))->handle($this->request());

        $this->assertSame(
            ['enter:A', 'enter:B', 'kernel', 'exit:B', 'exit:A'],
            $log->getArrayCopy()
        );
        $this->assertSame($kernelResponse, $response);
    }

    public function test_outermost_first_ordering_with_three_middleware(): void
    {
        // Pins the "outermost first" contract with three layers: request travels
        // A→B→C→kernel and response unwinds C→B→A.
        $log = new ArrayObject;
        $kernelResponse = $this->response();
        $kernel = new RecordingKernel($kernelResponse, $log);

        $pipeline = new Pipeline([
            new RecordingMiddleware('A', $log),
            new RecordingMiddleware('B', $log),
            new RecordingMiddleware('C', $log),
        ], $kernel);

        $response = $pipeline->handle($this->request());

        $this->assertSame(
            ['enter:A', 'enter:B', 'enter:C', 'kernel', 'exit:C', 'exit:B', 'exit:A'],
            $log->getArrayCopy()
        );
        $this->assertSame($kernelResponse, $response);
    }

    public function test_outermost_middleware_can_short_circuit(): void
    {
        // A short-circuit at position 0 must skip every inner layer and the kernel.
        $log = new ArrayObject;
        $kernel = new RecordingKernel($this->response(), $log);
        $blocked = $this->response();

        $pipeline = new Pipeline([
            new RecordingMiddleware('A', $log, shortCircuit: $blocked),
            new RecordingMiddleware('B', $log),
        ], $kernel);

        $response = $pipeline->handle($this->request());

        $this->assertSame($blocked, $response);
        $this->assertFalse($kernel->called, 'kernel must not run when outermost middleware short-circuits');
        $this->assertSame(['enter:A', 'short:A'], $log->getArrayCopy());
    }

    public function test_kernel_response_propagates_unmodified_through_pass_through_middleware(): void
    {
        // Each pass-through middleware must return exactly what the inner handler
        // returned — not a copy or a different instance.
        $log = new ArrayObject;
        $kernelResponse = $this->response();
        $kernel = new RecordingKernel($kernelResponse, $log);

        $response = (new Pipeline([
            new RecordingMiddleware('A', $log),
            new RecordingMiddleware('B', $log),
        ], $kernel))->handle($this->request());

        $this->assertSame($kernelResponse, $response);
    }
}
