<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\NegotiatingErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\Testing\ErrorRendererContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;

/** The contract as htmx sees it: a fragment, sent to the error region when there is one. */
#[CoversClass(NegotiatingErrorRenderer::class)]
final class NegotiatingErrorRendererHtmxTest extends ErrorRendererContractTestCase
{
    protected function renderer(?string $target = '#app-error'): ErrorRendererInterface
    {
        $psr17 = new Psr17Factory;

        return new NegotiatingErrorRenderer(new Responder($psr17, $psr17), $target);
    }

    protected function request(): ServerRequestInterface
    {
        // htmx sends text/html too, which is why it is asked about first.
        return (new Psr17Factory)->createServerRequest('POST', '/x')
            ->withHeader('HX-Request', 'true')
            ->withHeader('Accept', 'text/html');
    }

    public function test_the_fragment_is_swapped_into_the_error_region(): void
    {
        $body = (string) $this->renderer()->render($this->context(new HttpException(403), 403))->getBody();

        $this->assertStringContainsString('hx-swap-oob="innerHTML:#app-error"', $body);
        $this->assertStringNotContainsString('<html', $body);
    }

    public function test_without_a_region_it_is_a_bare_fragment(): void
    {
        $body = (string) $this->renderer(null)->render($this->context(new HttpException(403), 403))->getBody();

        $this->assertStringStartsWith('<h1>403</h1>', $body);
    }

    public function test_a_json_accept_does_not_win_over_htmx(): void
    {
        $request = $this->request()->withHeader('Accept', 'application/json');
        $response = $this->renderer()->render(new ErrorContext(new HttpException(403), $request, 403, false));

        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }
}
