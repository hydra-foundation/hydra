<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ErrorContext;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class PlainTextErrorRendererTest extends TestCase
{
    private function renderer(): PlainTextErrorRenderer
    {
        $psr17 = new Psr17Factory;

        return new PlainTextErrorRenderer(new Responder($psr17, $psr17));
    }

    private function context(\Throwable $error, int $status, bool $debug): ErrorContext
    {
        return new ErrorContext($error, $this->createStub(ServerRequestInterface::class), $status, $debug);
    }

    public function testRendersPlainTextWithTheGivenStatus(): void
    {
        $response = $this->renderer()->render($this->context(new HttpException(404), 404, false));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testProductionBodyHidesGenericThrowableDetails(): void
    {
        $response = $this->renderer()->render(
            $this->context(new RuntimeException('secret db dsn leaked here'), 500, false)
        );

        $body = (string) $response->getBody();
        $this->assertSame('Internal Server Error', $body);
        $this->assertStringNotContainsString('secret db dsn', $body);
    }

    public function testProductionBodyShowsAnHttpExceptionMessage(): void
    {
        $response = $this->renderer()->render(
            $this->context(new HttpException(403, 'not yours'), 403, false)
        );

        $this->assertSame('not yours', (string) $response->getBody());
    }

    public function testDebugBodyIncludesClassMessageOriginAndTrace(): void
    {
        $response = $this->renderer()->render(
            $this->context(new RuntimeException('boom'), 500, true)
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString(RuntimeException::class, $body);
        $this->assertStringContainsString('boom', $body);
        $this->assertStringContainsString(__FILE__, $body); // origin file
        $this->assertStringContainsString('#0', $body);      // stack trace
    }
}
