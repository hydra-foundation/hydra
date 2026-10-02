<?php

declare(strict_types=1);

namespace Hydra\Seo\Tests\Unit;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Hydra\Seo\AtomFeed;
use Hydra\Seo\FeedEntry;
use Hydra\Seo\Url;
use Hydra\Seo\Xml;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(AtomFeed::class)]
#[CoversClass(FeedEntry::class)]
#[CoversClass(Url::class)]
#[CoversClass(Xml::class)]
final class AtomFeedTest extends TestCase
{
    private const NS = 'http://www.w3.org/2005/Atom';

    private static function feed(?ClockInterface $clock = null): AtomFeed
    {
        return new AtomFeed(
            baseUrl: 'https://williamhleucka.com',
            path: '/feed.xml',
            title: 'William Hleucka — Writing',
            homePath: '/blog',
            author: 'William Hleucka',
            subtitle: 'Code, hockey, and whatever else is on my mind.',
            clock: $clock,
        );
    }

    /** @param list<string> $tags */
    private static function entry(string $slug, string $updated, ?string $content = null, array $tags = []): FeedEntry
    {
        return new FeedEntry(
            path: "/blog/$slug",
            title: ucfirst($slug),
            published: new DateTimeImmutable('2026-10-01T08:00:00-06:00'),
            updated: new DateTimeImmutable($updated),
            summary: "About $slug.",
            content: $content,
            categories: $tags,
        );
    }

    private static function xpath(string $xml): DOMXPath
    {
        $doc = new DOMDocument;
        self::assertTrue($doc->loadXML($xml), 'not well-formed XML');
        self::assertSame('feed', $doc->documentElement?->localName);
        self::assertSame(self::NS, $doc->documentElement->namespaceURI);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('a', self::NS);

        return $xpath;
    }

    private static function text(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node?->textContent;
    }

    /** @return list<string> the text of every node the query finds, in order */
    private static function all(DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    private static function attr(DOMXPath $xpath, string $query, string $name): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof DOMElement ? $node->getAttribute($name) : null;
    }

    public function test_the_feed_carries_everything_rfc_4287_requires(): void
    {
        $feed = self::feed();
        $feed->add(self::entry('first', '2026-10-02T09:00:00Z'));
        $xml = $feed->xml();
        $xpath = self::xpath($xml);

        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>' . "\n", $xml);
        self::assertSame('https://williamhleucka.com/feed.xml', self::text($xpath, '/a:feed/a:id'));
        self::assertSame('William Hleucka — Writing', self::text($xpath, '/a:feed/a:title'));
        self::assertSame('Code, hockey, and whatever else is on my mind.', self::text($xpath, '/a:feed/a:subtitle'));
        self::assertSame('2026-10-02T09:00:00+00:00', self::text($xpath, '/a:feed/a:updated'));
        self::assertSame('William Hleucka', self::text($xpath, '/a:feed/a:author/a:name'));
        self::assertSame('https://williamhleucka.com/feed.xml', self::attr($xpath, '/a:feed/a:link[@rel="self"]', 'href'));
        self::assertSame('application/atom+xml', self::attr($xpath, '/a:feed/a:link[@rel="self"]', 'type'));
        self::assertSame('https://williamhleucka.com/blog', self::attr($xpath, '/a:feed/a:link[@rel="alternate"]', 'href'));
    }

    public function test_an_entry_has_its_id_link_dates_summary_content_and_categories(): void
    {
        $feed = self::feed();
        $feed->add(self::entry('first', '2026-10-02T09:00:00-06:00', '<p>Hello <em>there</em></p>', ['code', 'hydra']));
        $xpath = self::xpath($feed->xml());
        $e = '/a:feed/a:entry';

        self::assertSame('https://williamhleucka.com/blog/first', self::text($xpath, "$e/a:id"));
        self::assertSame('First', self::text($xpath, "$e/a:title"));
        self::assertSame('https://williamhleucka.com/blog/first', self::attr($xpath, "$e/a:link[@rel=\"alternate\"]", 'href'));
        self::assertSame('2026-10-01T14:00:00+00:00', self::text($xpath, "$e/a:published"));
        self::assertSame('2026-10-02T15:00:00+00:00', self::text($xpath, "$e/a:updated"));
        self::assertSame('About first.', self::text($xpath, "$e/a:summary"));
        self::assertSame('html', self::attr($xpath, "$e/a:content", 'type'));
        self::assertSame('<p>Hello <em>there</em></p>', self::text($xpath, "$e/a:content"));
        self::assertSame(['code', 'hydra'], self::all($xpath, "$e/a:category/@term"));
    }

    public function test_an_entry_without_content_has_no_content_element(): void
    {
        $feed = self::feed();
        $feed->add(self::entry('first', '2026-10-02T09:00:00Z'));

        self::assertSame(0, self::xpath($feed->xml())->query('/a:feed/a:entry/a:content')?->length);
    }

    public function test_entries_come_out_newest_updated_first_and_the_feed_takes_the_newest_time(): void
    {
        $feed = self::feed();
        $feed->add(self::entry('old', '2026-09-01T00:00:00Z'))
            ->add(self::entry('newest', '2026-10-05T00:00:00Z'))
            ->add(self::entry('middle', '2026-09-20T00:00:00Z'));
        $xpath = self::xpath($feed->xml());

        self::assertSame(['Newest', 'Middle', 'Old'], self::all($xpath, '/a:feed/a:entry/a:title'));
        self::assertSame('2026-10-05T00:00:00+00:00', self::text($xpath, '/a:feed/a:updated'));
    }

    public function test_an_empty_feed_is_valid_and_dated_now(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-02T12:00:00Z');
            }
        };
        $xpath = self::xpath(self::feed($clock)->xml());

        self::assertSame('2026-10-02T12:00:00+00:00', self::text($xpath, '/a:feed/a:updated'));
        self::assertSame(0, $xpath->query('/a:feed/a:entry')?->length);
    }

    public function test_an_empty_feed_with_no_clock_uses_the_time_it_is_built(): void
    {
        $before = time();
        $updated = self::text(self::xpath(self::feed()->xml()), '/a:feed/a:updated');

        self::assertNotNull($updated);
        self::assertGreaterThanOrEqual($before, strtotime($updated));
    }

    public function test_a_feed_with_no_subtitle_has_no_subtitle_element(): void
    {
        $feed = new AtomFeed('https://example.com', '/feed.xml', 'T', '/', 'A');

        self::assertSame(0, self::xpath($feed->xml())->query('/a:feed/a:subtitle')?->length);
    }

    public function test_html_and_cdata_terminators_in_content_survive_as_text(): void
    {
        $feed = self::feed();
        $feed->add(self::entry('tricky', '2026-10-02T09:00:00Z', 'a ]]> b & <script>c</script>'));
        $xml = $feed->xml();

        self::assertStringNotContainsString('<script>', $xml);
        self::assertSame('a ]]> b & <script>c</script>', self::text(self::xpath($xml), '/a:feed/a:entry/a:content'));
    }

    public function test_titles_summaries_and_categories_are_escaped_and_cleaned(): void
    {
        $feed = self::feed();
        $feed->add(new FeedEntry(
            path: '/blog/x',
            title: "Tom & \"Jerry\" <b>\x07",
            published: new DateTimeImmutable('2026-10-01T00:00:00Z'),
            updated: new DateTimeImmutable('2026-10-01T00:00:00Z'),
            summary: "Less < more\x0B",
            categories: ['c&s "quoted"'],
        ));
        $xpath = self::xpath($feed->xml());

        self::assertSame('Tom & "Jerry" <b>', self::text($xpath, '/a:feed/a:entry/a:title'));
        self::assertSame('Less < more', self::text($xpath, '/a:feed/a:entry/a:summary'));
        self::assertSame('c&s "quoted"', self::attr($xpath, '/a:feed/a:entry/a:category', 'term'));
    }

    public function test_an_entry_needs_a_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The feed entry /blog/x has no title.');

        new FeedEntry('/blog/x', '  ', new DateTimeImmutable, new DateTimeImmutable, 'S');
    }

    public function test_an_entry_path_is_from_the_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A feed entry path must start with "/"; got "blog/x".');

        new FeedEntry('blog/x', 'X', new DateTimeImmutable, new DateTimeImmutable, 'S');
    }

    public function test_the_feed_paths_and_base_url_are_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AtomFeed baseUrl must look like https://example.com');

        new AtomFeed('https://example.com/blog', '/feed.xml', 'T', '/', 'A');
    }

    public function test_the_feed_path_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A feed path must start with "/"; got "feed.xml".');

        new AtomFeed('https://example.com', 'feed.xml', 'T', '/', 'A');
    }

    public function test_the_home_path_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A feed home path must start with "/"; got "blog".');

        new AtomFeed('https://example.com', '/feed.xml', 'T', 'blog', 'A');
    }
}
