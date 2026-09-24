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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The contract as a browser sees it, and the choosing itself. The JSON and htmx
 * answers run the contract in their own classes.
 */
#[CoversClass(NegotiatingErrorRenderer::class)]
final class NegotiatingErrorRendererTest extends ErrorRendererContractTestCase
{
    private const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

    protected function renderer(): ErrorRendererInterface
    {
        $psr17 = new Psr17Factory;

        return new NegotiatingErrorRenderer(new Responder($psr17, $psr17));
    }

    protected function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/x')->withHeader('Accept', self::BROWSER);
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepts(): iterable
    {
        yield 'a browser' => [self::BROWSER, 'text/html'];
        yield 'an API client' => ['application/json', 'application/problem+json'];
        yield 'a problem+json client' => ['application/problem+json', 'application/problem+json'];
        yield 'a vendor json type' => ['application/vnd.api+json', 'application/problem+json'];
        yield 'json preferred by q' => ['text/html;q=0.5, application/json', 'application/problem+json'];
        yield 'html preferred by q' => ['application/json;q=0.4, text/html;q=0.9', 'text/html'];
        yield 'a tie goes to the first' => ['application/json, text/html', 'application/problem+json'];
        yield 'json refused outright' => ['application/json;q=0, text/html', 'text/html'];
        yield 'curl' => ['*/*', 'text/plain'];
        yield 'no header at all' => ['', 'text/plain'];
        yield 'something else' => ['image/png', 'text/plain'];
    }

    #[DataProvider('accepts')]
    public function test_it_answers_in_the_format_asked_for(string $accept, string $type): void
    {
        $this->assertStringStartsWith($type, $this->answer($accept)->getHeaderLine('Content-Type'));
    }

    public function test_every_answer_varies_on_what_chose_it(): void
    {
        foreach (['*/*', 'application/json', self::BROWSER] as $accept) {
            $this->assertSame('Accept, HX-Request', $this->answer($accept)->getHeaderLine('Vary'));
        }
    }

    public function test_problem_details_carry_the_status_and_its_title(): void
    {
        $problem = $this->decode($this->answer('application/json', new HttpException(404), 404));

        $this->assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], $problem);
    }

    public function test_an_http_exception_message_is_the_detail(): void
    {
        $problem = $this->decode($this->answer('application/json', new HttpException(422, 'that email is already taken'), 422));

        $this->assertSame('Unprocessable Entity', $problem['title']);
        $this->assertSame('that email is already taken', $problem['detail']);
    }

    public function test_debug_problem_details_name_the_exception(): void
    {
        $error = new RuntimeException('boom');
        $problem = $this->decode($this->answer('application/json', $error, 500, debug: true));

        $this->assertSame(RuntimeException::class, $problem['exception']);
        $this->assertSame('boom', $problem['message']);
        $this->assertSame($error->getLine(), $problem['line']);
    }

    public function test_the_page_is_a_whole_document(): void
    {
        $body = (string) $this->answer(self::BROWSER, new HttpException(404), 404)->getBody();

        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertStringContainsString('<title>404 Not Found</title>', $body);
    }

    private function answer(string $accept, ?\Throwable $error = null, int $status = 500, bool $debug = false): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/x')->withHeader('Accept', $accept);

        return $this->renderer()->render(new ErrorContext($error ?? new RuntimeException('x'), $request, $status, $debug));
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
