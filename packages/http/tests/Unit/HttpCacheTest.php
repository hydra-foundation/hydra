<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Http\ConditionalGet;
use Hydra\Http\HttpCache;
use Hydra\Http\Release;
use Hydra\Http\Responder;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a controller says about caching a page, the headers that become, and
 * whether a request already holds that page: RFC 9110's conditional GET, with
 * an ETag built from what the page is made of rather than its bytes, which
 * carry a fresh CSP nonce every time.
 */
#[CoversClass(HttpCache::class)]
#[CoversClass(Release::class)]
#[CoversClass(ConditionalGet::class)]
#[CoversClass(Responder::class)]
final class HttpCacheTest extends TestCase
{
    private const MODIFIED = 'Wed, 07 Oct 2026 16:30:00 GMT';

    /** @return iterable<string, array{HttpCache, string}> */
    public static function policies(): iterable
    {
        yield 'public, kept a while' => [HttpCache::public(maxAge: 300), 'public, max-age=300'];
        yield 'public, always revalidated' => [HttpCache::public(), 'public, no-cache'];
        yield 'private, always revalidated' => [HttpCache::private(), 'private, no-cache'];
        yield 'private, kept a while' => [HttpCache::private(maxAge: 60), 'private, max-age=60'];
        yield 'never kept' => [HttpCache::noStore(), 'no-store'];
    }

    #[DataProvider('policies')]
    public function test_each_policy_says_one_thing(HttpCache $cache, string $header): void
    {
        $this->assertSame(['Cache-Control' => $header], $cache->headers());
    }

    public function test_a_negative_age_is_a_mistake(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpCache::public(maxAge: -1);
    }

    public function test_the_etag_is_weak_and_made_of_its_parts_and_the_release(): void
    {
        $release = new Release('v1');
        $etag = HttpCache::public()->etag('hello', 1790000000)->headers($release)['ETag'] ?? '';

        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{32}"$/', $etag);
        $this->assertSame($etag, HttpCache::public()->etag('hello', 1790000000)->headers(new Release('v1'))['ETag'] ?? null, 'stable');
        $this->assertNotSame($etag, HttpCache::public()->etag('hello', 1790000001)->headers($release)['ETag'] ?? null, 'a part changed');
        $this->assertNotSame($etag, HttpCache::public()->etag('hello', 1790000000)->headers(new Release('v2'))['ETag'] ?? null, 'a deploy');
        $this->assertNotSame($etag, HttpCache::public()->etag('hello', 1790000000)->headers()['ETag'] ?? null, 'no release is its own');
    }

    public function test_parts_cannot_run_into_each_other(): void
    {
        $a = HttpCache::public()->etag('ab', 'c')->headers()['ETag'] ?? null;
        $b = HttpCache::public()->etag('a', 'bc')->headers()['ETag'] ?? null;

        $this->assertNotSame($a, $b);
    }

    public function test_a_part_that_prints_is_what_it_prints(): void
    {
        $uri = new class implements \Stringable {
            public function __toString(): string
            {
                return '/posts/hello';
            }
        };

        $this->assertSame(
            HttpCache::public()->etag('/posts/hello')->headers()['ETag'] ?? null,
            HttpCache::public()->etag($uri)->headers()['ETag'] ?? null,
        );
    }

    public function test_an_etag_needs_something_to_be_made_of(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpCache::public()->etag();
    }

    public function test_last_modified_is_an_http_date_in_gmt_to_the_second(): void
    {
        $at = new DateTimeImmutable('2026-10-07 10:30:00.75', new DateTimeZone('America/Edmonton'));

        $this->assertSame(self::MODIFIED, HttpCache::public()->lastModified($at)->headers()['Last-Modified'] ?? null);
    }

    public function test_a_policy_is_a_value_each_call_returns_a_new_one(): void
    {
        $plain = HttpCache::public();
        $plain->etag('x');
        $plain->lastModified(new DateTimeImmutable);

        $this->assertSame(['Cache-Control' => 'public, no-cache'], $plain->headers());
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function requests(): iterable
    {
        $etag = 'W/"abc"';

        yield 'no condition' => [[], false];
        yield 'the same etag' => [['If-None-Match' => $etag], true];
        yield 'the etag sent strong' => [['If-None-Match' => '"abc"'], true];
        yield 'one of a list' => [['If-None-Match' => '"x", W/"abc" , "y"'], true];
        yield 'another etag' => [['If-None-Match' => 'W/"abd"'], false];
        yield 'any' => [['If-None-Match' => '*'], true];
        yield 'any, spaced' => [['If-None-Match' => ' * '], true];
        yield 'not modified since' => [['If-Modified-Since' => self::MODIFIED], true];
        yield 'checked later' => [['If-Modified-Since' => 'Thu, 08 Oct 2026 00:00:00 GMT'], true];
        yield 'checked earlier' => [['If-Modified-Since' => 'Wed, 07 Oct 2026 16:29:59 GMT'], false];
        yield 'a date nobody could mean' => [['If-Modified-Since' => 'yesterday'], false];
        yield 'etag wins over the date' => [['If-None-Match' => 'W/"old"', 'If-Modified-Since' => self::MODIFIED], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('requests')]
    public function test_a_request_holds_the_page_per_rfc_9110(array $headers, bool $fresh): void
    {
        $this->assertSame($fresh, ConditionalGet::fresh($this->request('GET', $headers), 'W/"abc"', self::MODIFIED));
    }

    public function test_only_a_read_can_be_fresh(): void
    {
        $this->assertTrue(ConditionalGet::fresh($this->request('HEAD', ['If-None-Match' => '*']), 'W/"a"', ''));
        $this->assertFalse(ConditionalGet::fresh($this->request('POST', ['If-None-Match' => '*']), 'W/"a"', ''));
    }

    public function test_without_validators_nothing_is_fresh(): void
    {
        $request = $this->request('GET', ['If-None-Match' => '*', 'If-Modified-Since' => self::MODIFIED]);

        $this->assertFalse(ConditionalGet::fresh($request, '', ''));
        $this->assertFalse(ConditionalGet::fresh($this->request('GET', ['If-Modified-Since' => self::MODIFIED]), 'W/"a"', ''), 'a date asked for, none to compare');
        $this->assertFalse(ConditionalGet::fresh($this->request('GET', ['If-None-Match' => 'W/"a"']), '', self::MODIFIED), 'an etag asked for, none to compare');
    }

    public function test_a_responder_answers_with_the_policy(): void
    {
        $responder = $this->responder(new Release('v1'));
        $cache = HttpCache::public(maxAge: 60)->etag('post')->lastModified(new DateTimeImmutable('@1790000000'));

        $page = $responder->cached($responder->html('<p>post</p>'), $cache);
        $etag = $page->getHeaderLine('ETag');

        $this->assertSame('<p>post</p>', (string) $page->getBody());
        $this->assertSame('public, max-age=60', $page->getHeaderLine('Cache-Control'));
        $this->assertSame($cache->headers(new Release('v1'))['ETag'] ?? null, $etag);
        $this->assertNotSame('', $page->getHeaderLine('Last-Modified'));

        $this->assertTrue($responder->isFresh($this->request('GET', ['If-None-Match' => $etag]), $cache));
        $this->assertTrue($responder->isFresh($this->request('GET', ['If-Modified-Since' => $page->getHeaderLine('Last-Modified')]), $cache));
        $this->assertFalse($responder->isFresh($this->request('GET'), $cache));

        $notModified = $responder->notModified($cache);
        $this->assertSame(304, $notModified->getStatusCode());
        $this->assertSame('', (string) $notModified->getBody());
        $this->assertSame($etag, $notModified->getHeaderLine('ETag'));
        $this->assertSame('public, max-age=60', $notModified->getHeaderLine('Cache-Control'));
        $this->assertSame($page->getHeaderLine('Last-Modified'), $notModified->getHeaderLine('Last-Modified'));
    }

    public function test_in_development_nothing_is_fresh(): void
    {
        $responder = $this->responder(new Release('dev', conditional: false));
        $cache = HttpCache::public()->etag('post');
        $etag = $cache->headers(new Release('dev'))['ETag'] ?? '';

        $this->assertFalse($responder->isFresh($this->request('GET', ['If-None-Match' => $etag]), $cache));
        $this->assertFalse($responder->isFresh($this->request('GET', ['If-None-Match' => '*']), $cache));
    }

    public function test_a_responder_without_a_release_still_compares(): void
    {
        $responder = $this->responder();
        $cache = HttpCache::public()->etag('post');
        $etag = $responder->cached($responder->html(''), $cache)->getHeaderLine('ETag');

        $this->assertTrue($responder->isFresh($this->request('GET', ['If-None-Match' => $etag]), $cache));
    }

    public function test_a_release_names_itself(): void
    {
        $this->assertSame('v0.30.0', (new Release('v0.30.0'))->id);
        $this->assertTrue((new Release('v0.30.0'))->conditional);
    }

    private function responder(?Release $release = null): Responder
    {
        $psr17 = new Psr17Factory;

        return new Responder($psr17, $psr17, release: $release);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, array $headers = []): ServerRequest
    {
        return new ServerRequest($method, '/posts/hello', $headers);
    }
}
