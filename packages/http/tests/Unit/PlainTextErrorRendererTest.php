<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\Testing\ErrorRendererContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The fallback renderer against the shared contract, plus what is its own: it is
 * the one that answers when nothing negotiated, so its format is fixed and its
 * debug output is the trace rather than a page.
 */
#[CoversClass(PlainTextErrorRenderer::class)]
#[CoversClass(ErrorContext::class)]
final class PlainTextErrorRendererTest extends ErrorRendererContractTestCase
{
    protected function renderer(): ErrorRendererInterface
    {
        $psr17 = new Psr17Factory;

        return new PlainTextErrorRenderer(new Responder($psr17, $psr17));
    }

    protected function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/x');
    }

    public function test_it_answers_in_plain_text(): void
    {
        $response = $this->renderer()->render($this->context(new RuntimeException('x'), 500));

        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function test_debug_output_is_the_exception_and_its_trace(): void
    {
        // No client to negotiate with and no markup to format: an operator
        // reading this wants the class, the location and the stack.
        $body = (string) $this->renderer()
            ->render($this->context(new RuntimeException('boom'), 500, debug: true))
            ->getBody();

        $this->assertStringContainsString(RuntimeException::class, $body);
        $this->assertStringContainsString('boom', $body);
        $this->assertStringContainsString(__FILE__, $body);
    }
}
