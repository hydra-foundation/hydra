<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Exceptions\MethodNotAllowedException;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use TypeError;

/** Records every log() call so tests can assert on what the middleware logged. */
final class SpyLogger extends AbstractLogger
{
    /** @var array<array{level:mixed,message:string,context:array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

final class ErrorHandlerMiddlewareTest extends TestCase
{
    private function responder(): Responder
    {
        $psr17 = new Psr17Factory;
        return new Responder($psr17, $psr17);
    }

    /** The default renderer, so these tests exercise the middleware end-to-end. */
    private function renderer(): PlainTextErrorRenderer
    {
        return new PlainTextErrorRenderer($this->responder());
    }

    private function request(): ServerRequestInterface
    {
        return $this->createStub(ServerRequestInterface::class);
    }

    private function handlerThrowing(\Throwable $e): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($e);

        return $handler;
    }

    public function testPassesResponseThroughWhenNoExceptionThrown(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expected);

        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $this->assertSame($expected, $middleware->process($this->request(), $handler));
    }

    public function testConvertsThrowableToA500(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process($this->request(), $this->handlerThrowing(new RuntimeException('boom')));

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testProductionResponseHidesDetails(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new RuntimeException('secret db dsn leaked here'))
        );

        $body = (string) $response->getBody();
        $this->assertSame('Internal Server Error', $body);
        $this->assertStringNotContainsString('secret db dsn', $body);
    }

    public function testDebugResponseIncludesExceptionDetails(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: true);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new RuntimeException('boom'))
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString(RuntimeException::class, $body);
        $this->assertStringContainsString('boom', $body);
    }

    public function testCatchesErrorsNotOnlyExceptions(): void
    {
        // A TypeError is a Throwable but not an Exception — must still become 500.
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process($this->request(), $this->handlerThrowing(new TypeError('bad type')));

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testLogsThrowableAtErrorLevelWithExceptionContext(): void
    {
        $logger = new SpyLogger;
        $exception = new RuntimeException('boom');
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false, logger: $logger);

        $response = $middleware->process($this->request(), $this->handlerThrowing($exception));

        // The fault is logged exactly once, at error level, with the Throwable
        // under the conventional PSR-3 'exception' key (not stringified).
        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($exception, $logger->records[0]['context']['exception']);
        // …and the client still gets its 500.
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testDoesNotLogWhenNoExceptionThrown(): void
    {
        $logger = new SpyLogger;
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($this->createStub(ResponseInterface::class));

        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false, logger: $logger);
        $middleware->process($this->request(), $handler);

        $this->assertSame([], $logger->records);
    }

    public function testHttpExceptionUsesItsOwnStatus(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process($this->request(), $this->handlerThrowing(new NotFoundException));

        $this->assertSame(404, $response->getStatusCode());
        // The body is the exception's own message, not the 500 default.
        $this->assertSame('Not Found', (string) $response->getBody());
    }

    public function testHttpExceptionMessageIsShownInProduction(): void
    {
        // An HttpException message is developer-authored and intentional
        // (e.g. abort(403, 'not yours')), so it reaches the client even in prod.
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new HttpException(403, 'not yours'))
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('not yours', (string) $response->getBody());
    }

    public function testHttpExceptionWithoutMessageFallsBackToReasonPhrase(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new HttpException(403))
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Forbidden', (string) $response->getBody());
    }

    public function testUnenumeratedStatusWithoutMessageFallsBackToGenericError(): void
    {
        // A status the Status enum doesn't carry (and no message) hits the
        // `Status::reasonFor($status) ?? 'Error'` fallback rather than blanking.
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new HttpException(418))
        );

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('Error', (string) $response->getBody());
    }

    public function testHttpExceptionHeadersAreApplied(): void
    {
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false);

        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new MethodNotAllowedException(['GET', 'POST']))
        );

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testClientErrorIsNotLoggedAsAFault(): void
    {
        $logger = new SpyLogger;
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false, logger: $logger);

        $middleware->process($this->request(), $this->handlerThrowing(new NotFoundException));

        $this->assertSame([], $logger->records, '4xx is an expected condition, not a fault');
    }

    public function testServerSideHttpExceptionIsLogged(): void
    {
        $logger = new SpyLogger;
        $exception = new HttpException(503, 'maintenance');
        $middleware = new ErrorHandlerMiddleware($this->renderer(), debug: false, logger: $logger);

        $response = $middleware->process($this->request(), $this->handlerThrowing($exception));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertCount(1, $logger->records);
        $this->assertSame(LogLevel::ERROR, $logger->records[0]['level']);
        $this->assertSame($exception, $logger->records[0]['context']['exception']);
    }

    public function testDelegatesToTheRendererWithTheResolvedContext(): void
    {
        // The middleware hands the renderer an ErrorContext carrying the caught
        // throwable, the same request instance, the mapped status, and the debug
        // flag — and returns whatever the renderer produced, untouched.
        $expected = $this->responder()->text('rendered by the app', 499);
        $request = $this->request();
        $exception = new HttpException(403, 'nope');

        $renderer = new class ($expected) implements ErrorRendererInterface {
            public ?ErrorContext $seen = null;
            public function __construct(private readonly ResponseInterface $response) {}
            public function render(ErrorContext $context): ResponseInterface
            {
                $this->seen = $context;
                return $this->response;
            }
        };

        $middleware = new ErrorHandlerMiddleware($renderer, debug: true);
        $response = $middleware->process($request, $this->handlerThrowing($exception));

        $this->assertSame($expected, $response);
        $this->assertNotNull($renderer->seen);
        $this->assertSame($exception, $renderer->seen->error);
        $this->assertSame($request, $renderer->seen->request);
        $this->assertSame(403, $renderer->seen->status);
        $this->assertTrue($renderer->seen->debug);
    }

    public function testMappedHeadersAreAppliedToTheRenderersResponse(): void
    {
        // Even a custom renderer's response gets the HttpException's headers.
        $renderer = new class ($this->responder()) implements ErrorRendererInterface {
            public function __construct(private readonly Responder $responder) {}
            public function render(ErrorContext $context): ResponseInterface
            {
                return $this->responder->html('<p>method not allowed</p>', $context->status);
            }
        };

        $middleware = new ErrorHandlerMiddleware($renderer, debug: false);
        $response = $middleware->process(
            $this->request(),
            $this->handlerThrowing(new MethodNotAllowedException(['GET', 'POST']))
        );

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testAThrowingRendererIsNotSwallowed(): void
    {
        // A renderer that blows up (broken template, failed view dependency) must
        // propagate to the kernel's last-resort boundary, not be caught here.
        $renderer = new class implements ErrorRendererInterface {
            public function render(ErrorContext $context): ResponseInterface
            {
                throw new RuntimeException('renderer exploded');
            }
        };

        $middleware = new ErrorHandlerMiddleware($renderer, debug: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('renderer exploded');

        $middleware->process($this->request(), $this->handlerThrowing(new RuntimeException('original')));
    }
}
