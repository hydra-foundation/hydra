<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use DateTimeImmutable;
use Hydra\Http\HttpCache;
use Hydra\Http\HttpCacheMiddleware;
use Hydra\Http\Release;
use Hydra\Http\Responder;
use Hydra\Http\Testing\FakeHandler;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The one place every response passes on its way out: a page that says
 * nothing about caching is never kept, a public page that hands out a cookie
 * is kept only by its reader, and a reader who already holds a page gets a
 * 304 and no body.
 */
#[CoversClass(HttpCacheMiddleware::class)]
final class HttpCacheMiddlewareTest extends TestCase
{
    private Psr17Factory $psr17;

    /** @var list<string> */
    private array $queued = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->psr17 = new Psr17Factory;
    }

    public function test_a_response_that_says_nothing_is_never_kept(): void
    {
        $response = $this->through($this->page());

        $this->assertSame(['no-store'], $response->getHeader('Cache-Control'));
    }

    public function test_a_response_that_says_something_is_left_to_say_it(): void
    {
        $response = $this->through($this->page()->withHeader('Cache-Control', 'public, max-age=60'));

        $this->assertSame(['public, max-age=60'], $response->getHeader('Cache-Control'));
    }

    public function test_a_public_page_setting_a_cookie_is_kept_only_by_its_reader(): void
    {
        $response = $this->through($this->page('public, max-age=60')->withHeader('Set-Cookie', 'a=b'));

        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('a=b', $response->getHeaderLine('Set-Cookie'), 'the cookie is left alone');
        $this->assertCount(1, $this->logged);
    }

    public function test_a_cookie_php_queued_itself_counts_too(): void
    {
        // The native session's cookie never reaches the PSR-7 response.
        $this->queued = ['Content-Type: text/html', 'set-cookie: hydra_session=abc; path=/; HttpOnly'];

        $response = $this->through($this->page('public, no-cache'));

        $this->assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
    }

    public function test_a_page_without_a_cookie_stays_public(): void
    {
        $this->queued = ['X-Powered-By: PHP'];

        $this->assertSame('public, no-cache', $this->through($this->page('public, no-cache'))->getHeaderLine('Cache-Control'));
        $this->assertSame([], $this->logged);
    }

    public function test_only_the_public_directive_is_downgraded(): void
    {
        $response = $this->through($this->page('max-age=60, public, publicity-stunt')->withHeader('Set-Cookie', 'a=b'));

        $this->assertSame('max-age=60, private, publicity-stunt', $response->getHeaderLine('Cache-Control'));
    }

    public function test_a_reader_holding_the_page_gets_a_304_and_no_body(): void
    {
        $page = $this->validated();
        $response = $this->through($page, ['If-None-Match' => $page->getHeaderLine('ETag')]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame($page->getHeaderLine('ETag'), $response->getHeaderLine('ETag'));
        $this->assertSame($page->getHeaderLine('Last-Modified'), $response->getHeaderLine('Last-Modified'));
        $this->assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('Accept', $response->getHeaderLine('Vary'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertFalse($response->hasHeader('Content-Type'));
        $this->assertFalse($response->hasHeader('Content-Length'));
    }

    public function test_by_date_too(): void
    {
        $page = $this->validated();

        $this->assertSame(304, $this->through($page, ['If-Modified-Since' => $page->getHeaderLine('Last-Modified')])->getStatusCode());
    }

    public function test_a_reader_with_an_older_copy_gets_the_page(): void
    {
        $response = $this->through($this->validated(), ['If-None-Match' => 'W/"old"']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<p>post</p>', (string) $response->getBody());
    }

    public function test_only_a_read_that_succeeded_becomes_a_304(): void
    {
        $page = $this->validated();
        $ask = ['If-None-Match' => '*'];

        $this->assertSame(200, $this->through($page, $ask, method: 'POST')->getStatusCode());
        $this->assertSame(404, $this->through($page->withStatus(404), $ask)->getStatusCode());
        $this->assertSame(304, $this->through($page, $ask, method: 'HEAD')->getStatusCode());
    }

    public function test_a_page_without_validators_is_always_sent(): void
    {
        $response = $this->through($this->page('public, max-age=60'), ['If-None-Match' => '*', 'If-Modified-Since' => 'Wed, 07 Oct 2026 16:30:00 GMT']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_in_development_nothing_is_a_304(): void
    {
        $page = $this->validated();
        $response = $this->through($page, ['If-None-Match' => $page->getHeaderLine('ETag')], release: new Release('dev', conditional: false));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'), 'the rest still applies');
    }

    public function test_the_304_keeps_a_downgrade(): void
    {
        $page = $this->validated()->withHeader('Set-Cookie', 'a=b');
        $response = $this->through($page, ['If-None-Match' => $page->getHeaderLine('ETag')]);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('private, max-age=60', $response->getHeaderLine('Cache-Control'));
    }

    public function test_without_a_queue_reader_it_asks_php(): void
    {
        // The CLI queues nothing, so the default reader finds no cookie.
        $middleware = new HttpCacheMiddleware($this->psr17);
        $response = $middleware->process(new ServerRequest('GET', '/'), FakeHandler::respondingWith($this->page('public, no-cache')));

        $this->assertSame('public, no-cache', $response->getHeaderLine('Cache-Control'));
    }

    private function validated(): ResponseInterface
    {
        $responder = new Responder($this->psr17, $this->psr17, release: new Release('v1'));
        $cache = HttpCache::public(maxAge: 60)->etag('post')->lastModified(new DateTimeImmutable('@1790000000'));

        return $responder->cached($this->page(), $cache)
            ->withHeader('Vary', 'Accept')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Length', '11');
    }

    private function page(?string $cacheControl = null): ResponseInterface
    {
        $page = $this->psr17->createResponse(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->psr17->createStream('<p>post</p>'));

        return $cacheControl === null ? $page : $page->withHeader('Cache-Control', $cacheControl);
    }

    /** @param array<string, string> $headers */
    private function through(ResponseInterface $response, array $headers = [], string $method = 'GET', ?Release $release = null): ResponseInterface
    {
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = "{$level}: {$message}";
            }
        };

        $middleware = new HttpCacheMiddleware(
            $this->psr17,
            $release ?? new Release('v1'),
            $logger,
            fn (): array => $this->queued,
        );

        return $middleware->process(new ServerRequest($method, '/posts/hello', $headers), FakeHandler::respondingWith($response));
    }
}
