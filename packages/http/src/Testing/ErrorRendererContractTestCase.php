<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorContext;
use Hydra\Http\Exceptions\HttpException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * The behaviour every error renderer owes its callers, published so an
 * application that replaces the shipped one can be run against it.
 *
 * Replacing this seam is close to routine — content negotiation, a branded
 * error page, a JSON envelope an API already promises — and it is the one seam
 * where a mistake is a disclosure rather than a bug. A renderer decides what an
 * exception's message does next, and a generic throwable's message is written
 * for an operator reading a log: a DSN, a query, a path on disk. Debug mode is
 * the only place that may be shown.
 */
abstract class ErrorRendererContractTestCase extends TestCase
{
    abstract protected function renderer(): ErrorRendererInterface;

    /** Any server request; the renderer under test decides what it reads from one. */
    abstract protected function request(): ServerRequestInterface;

    protected function context(\Throwable $error, int $status, bool $debug = false): ErrorContext
    {
        return new ErrorContext($error, $this->request(), $status, $debug);
    }

    public function test_the_response_carries_the_status_it_was_given(): void
    {
        // The status is resolved before the renderer is reached. A renderer that
        // wrote its own would turn a 404 into a 200 and tell a crawler the page
        // is fine.
        foreach ([400, 403, 404, 422, 500] as $status) {
            $response = $this->renderer()->render($this->context(new HttpException($status), $status));

            $this->assertSame($status, $response->getStatusCode());
        }
    }

    public function test_the_response_declares_a_content_type(): void
    {
        $response = $this->renderer()->render($this->context(new HttpException(500), 500));

        $this->assertNotSame('', $response->getHeaderLine('Content-Type'));
    }

    public function test_the_body_is_not_empty(): void
    {
        // A blank 500 is indistinguishable from a connection that died, and it
        // is what a renderer that forgot to write its body produces.
        $response = $this->renderer()->render($this->context(new HttpException(500), 500));

        $this->assertNotSame('', (string) $response->getBody());
    }

    public function test_an_http_exception_message_is_shown(): void
    {
        // Developer-authored and meant for the client: it is how a controller
        // says why. ErrorContext::clientMessage() is where it comes from.
        $response = $this->renderer()->render(
            $this->context(new HttpException(422, 'that email is already taken'), 422),
        );

        $this->assertStringContainsString(
            'that email is already taken',
            (string) $response->getBody(),
        );
    }

    public function test_a_status_with_no_message_still_says_something(): void
    {
        $response = $this->renderer()->render($this->context(new HttpException(404), 404));

        $this->assertStringContainsString('Not Found', (string) $response->getBody());
    }

    public function test_a_generic_throwable_message_never_reaches_the_client(): void
    {
        // The disclosure this contract exists for. Anything not an HttpException
        // was raised for an operator, not a visitor.
        $response = $this->renderer()->render(
            $this->context(new RuntimeException('pgsql://app:hunter2@db.internal/main'), 500),
        );
        $body = (string) $response->getBody();

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('db.internal', $body);
        $this->assertStringNotContainsString(RuntimeException::class, $body);
        $this->assertStringContainsString('Internal Server Error', $body);
    }

    public function test_a_generic_throwable_leaks_nothing_through_its_previous_either(): void
    {
        // A wrapped exception is the usual shape — a repository catching a PDO
        // error and rethrowing its own — and the secret is in the one underneath.
        $error = new RuntimeException('query failed', 0, new RuntimeException('hunter2'));
        $body = (string) $this->renderer()->render($this->context($error, 500))->getBody();

        $this->assertStringNotContainsString('hunter2', $body);
    }

    public function test_debug_mode_is_the_only_thing_that_changes_that(): void
    {
        // Not which detail a renderer shows — a plain-text one prints the trace,
        // an HTML one may show a formatted page — only that debug is what the
        // decision turns on, so a production default cannot accidentally leak.
        $error = new RuntimeException('a detail worth seeing in development');

        $production = (string) $this->renderer()->render($this->context($error, 500))->getBody();
        $debug = (string) $this->renderer()->render($this->context($error, 500, debug: true))->getBody();

        $this->assertNotSame($production, $debug);
    }

    public function test_a_message_carrying_markup_is_inert_in_whatever_format_it_lands(): void
    {
        // An HttpException message reaches the body, so it is a reflection point.
        // What "safe" means there depends on the format, and both halves are a
        // way to get this wrong: a markup renderer that forgets to escape hands
        // the client a live tag, and a text renderer that escapes anyway shows a
        // visitor `&lt;` where the developer wrote `<`.
        $response = $this->renderer()->render(
            $this->context(new HttpException(400, '<script>alert(1)</script>'), 400),
        );
        $body = (string) $response->getBody();

        if (str_contains($response->getHeaderLine('Content-Type'), 'html')) {
            $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
            $this->assertStringContainsString('&lt;script&gt;', $body);

            return;
        }

        $this->assertStringContainsString('<script>alert(1)</script>', $body);
    }
}
