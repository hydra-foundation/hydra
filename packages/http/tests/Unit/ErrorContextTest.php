<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ErrorContext;
use Hydra\Http\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ErrorContextTest extends TestCase
{
    private function context(\Throwable $error, int $status, bool $debug = false): ErrorContext
    {
        return new ErrorContext($error, $this->createStub(ServerRequestInterface::class), $status, $debug);
    }

    public function testClientMessageShowsAnHttpExceptionsAuthoredMessage(): void
    {
        $context = $this->context(new HttpException(403, 'not yours'), 403);

        $this->assertSame('not yours', $context->clientMessage());
    }

    public function testClientMessageFallsBackToReasonPhraseForAMessagelessHttpException(): void
    {
        $context = $this->context(new HttpException(403), 403);

        $this->assertSame('Forbidden', $context->clientMessage());
    }

    public function testClientMessageNeverLeaksAGenericThrowablesMessage(): void
    {
        // A non-HttpException may carry internals (a DSN, a path) — its message
        // must never reach the client; the reason phrase stands in.
        $context = $this->context(new RuntimeException('secret dsn=user:pw@db'), 500);

        $this->assertSame('Internal Server Error', $context->clientMessage());
        $this->assertStringNotContainsString('secret', $context->clientMessage());
    }

    public function testClientMessageFallsBackToGenericErrorForAnUnknownStatus(): void
    {
        // A status the Status enum doesn't carry, with no usable message.
        $context = $this->context(new RuntimeException('x'), 599);

        $this->assertSame('Error', $context->clientMessage());
    }

    public function testExposesTheThrowableRequestStatusAndDebugFlag(): void
    {
        $error = new RuntimeException('boom');
        $request = $this->createStub(ServerRequestInterface::class);

        $context = new ErrorContext($error, $request, 500, true);

        $this->assertSame($error, $context->error);
        $this->assertSame($request, $context->request);
        $this->assertSame(500, $context->status);
        $this->assertTrue($context->debug);
    }
}
