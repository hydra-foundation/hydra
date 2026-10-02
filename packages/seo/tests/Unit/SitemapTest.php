<?php

declare(strict_types=1);

namespace Hydra\Seo\Tests\Unit;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Hydra\Seo\Sitemap;
use InvalidArgumentException;
use LengthException;
use Hydra\Seo\Url;
use Hydra\Seo\Xml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Sitemap::class)]
#[CoversClass(Url::class)]
#[CoversClass(Xml::class)]
final class SitemapTest extends TestCase
{
    private const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    /** @return list<array{loc: string, lastmod: ?string}> */
    private static function urls(string $xml): array
    {
        $doc = new DOMDocument;
        self::assertTrue($doc->loadXML($xml), 'not well-formed XML');
        self::assertSame('urlset', $doc->documentElement?->localName);
        self::assertSame(self::NS, $doc->documentElement->namespaceURI);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('s', self::NS);
        $urls = [];
        foreach ($xpath->query('/s:urlset/s:url') ?: [] as $url) {
            $lastmod = $xpath->query('s:lastmod', $url)?->item(0);
            $urls[] = [
                'loc' => (string) $xpath->query('s:loc', $url)?->item(0)?->textContent,
                'lastmod' => $lastmod?->textContent,
            ];
        }

        return $urls;
    }

    public function test_it_lists_absolute_urls_with_their_lastmod_in_utc(): void
    {
        $xml = (new Sitemap('https://williamhleucka.com'))
            ->add('/', new DateTimeImmutable('2026-10-02T09:15:00-06:00'))
            ->add('/resume', null)
            ->xml();

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n", $xml);
        self::assertSame([
            ['loc' => 'https://williamhleucka.com/', 'lastmod' => '2026-10-02T15:15:00+00:00'],
            ['loc' => 'https://williamhleucka.com/resume', 'lastmod' => null],
        ], self::urls($xml));
    }

    public function test_it_prints_no_priority_or_changefreq(): void
    {
        $xml = (new Sitemap('https://example.com'))->add('/', new DateTimeImmutable)->xml();

        self::assertStringNotContainsString('priority', $xml);
        self::assertStringNotContainsString('changefreq', $xml);
    }

    public function test_a_path_is_escaped_for_xml(): void
    {
        $xml = (new Sitemap('https://example.com'))->add('/tag/c&s<"x">', null)->xml();

        self::assertStringContainsString('<loc>https://example.com/tag/c&amp;s&lt;&quot;x&quot;&gt;</loc>', $xml);
        self::assertSame('https://example.com/tag/c&s<"x">', self::urls($xml)[0]['loc']);
    }

    public function test_a_character_xml_cannot_hold_is_dropped_rather_than_breaking_the_file(): void
    {
        $xml = (new Sitemap('https://example.com'))->add("/a\x01b\x1Fc", null)->xml();

        self::assertSame('https://example.com/abc', self::urls($xml)[0]['loc']);
    }

    public function test_a_path_added_twice_is_listed_once_with_the_later_lastmod(): void
    {
        $xml = (new Sitemap('https://example.com'))
            ->add('/a', new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->add('/b', null)
            ->add('/a', new DateTimeImmutable('2026-03-01T00:00:00Z'))
            ->add('/a', new DateTimeImmutable('2026-02-01T00:00:00Z'))
            ->xml();

        self::assertSame([
            ['loc' => 'https://example.com/a', 'lastmod' => '2026-03-01T00:00:00+00:00'],
            ['loc' => 'https://example.com/b', 'lastmod' => null],
        ], self::urls($xml));
    }

    public function test_a_dated_repeat_beats_an_undated_one(): void
    {
        $xml = (new Sitemap('https://example.com'))
            ->add('/a', new DateTimeImmutable('2026-01-01T00:00:00Z'))
            ->add('/a', null)
            ->xml();

        self::assertSame('2026-01-01T00:00:00+00:00', self::urls($xml)[0]['lastmod']);
    }

    public function test_add_all_maps_each_item_to_a_path_and_date(): void
    {
        $posts = [['slug' => 'one', 'at' => '2026-10-01T00:00:00Z'], ['slug' => 'two', 'at' => null]];

        $xml = (new Sitemap('https://example.com'))
            ->addAll($posts, static fn (array $p): array => [
                '/blog/' . $p['slug'],
                $p['at'] === null ? null : new DateTimeImmutable($p['at']),
            ])
            ->xml();

        self::assertSame(['https://example.com/blog/one', 'https://example.com/blog/two'], array_column(self::urls($xml), 'loc'));
    }

    public function test_an_empty_sitemap_is_still_valid(): void
    {
        self::assertSame([], self::urls((new Sitemap('https://example.com'))->xml()));
    }

    public function test_add_builds_on_the_same_sitemap(): void
    {
        $sitemap = new Sitemap('https://example.com');
        $sitemap->add('/a', null);
        $sitemap->add('/b', null);

        self::assertCount(2, self::urls($sitemap->xml()));
    }

    public function test_a_path_must_be_from_the_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A sitemap path must start with "/"; got "blog".');

        (new Sitemap('https://example.com'))->add('blog', null);
    }

    public function test_a_bad_base_url_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sitemap baseUrl must look like https://example.com (no path, no trailing slash); got "https://example.com/".');

        new Sitemap('https://example.com/');
    }

    public function test_more_than_50000_urls_are_refused(): void
    {
        $sitemap = (new Sitemap('https://example.com'))
            ->addAll(range(1, 50_000), static fn (int $i): array => ["/p/$i", null]);

        self::assertCount(50_000, self::urls($sitemap->xml()));

        $this->expectException(LengthException::class);
        $this->expectExceptionMessage('A sitemap holds at most 50000 URLs; split it into a sitemap index (not supported yet).');

        $sitemap->add('/one-more', null)->xml();
    }
}
