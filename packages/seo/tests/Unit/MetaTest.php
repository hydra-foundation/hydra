<?php

declare(strict_types=1);

namespace Hydra\Seo\Tests\Unit;

use Hydra\Seo\Image;
use Hydra\Seo\Meta;
use Hydra\Seo\SiteMeta;
use DateTimeImmutable;
use Hydra\View\HtmlView;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Meta::class)]
#[CoversClass(SiteMeta::class)]
#[CoversClass(Image::class)]
final class MetaTest extends TestCase
{
    private SiteMeta $site;

    protected function setUp(): void
    {
        $this->site = new SiteMeta(
            baseUrl: 'https://williamhleucka.com',
            siteName: 'William Hleucka',
            defaultImage: new Image('/img/share.png', 1200, 630, 'William Hleucka'),
            titleFormat: '%s — William Hleucka',
        );
    }

    public function test_a_page_prints_every_tag_in_order(): void
    {
        $meta = $this->site->page('Resume', 'Full-stack developer at Chief Systems, Lethbridge.', '/resume');

        self::assertSame(
            '<title>Resume — William Hleucka</title>' . "\n"
            . '<meta name="description" content="Full-stack developer at Chief Systems, Lethbridge.">' . "\n"
            . '<link rel="canonical" href="https://williamhleucka.com/resume">' . "\n"
            . '<meta property="og:type" content="website">' . "\n"
            . '<meta property="og:site_name" content="William Hleucka">' . "\n"
            . '<meta property="og:title" content="Resume">' . "\n"
            . '<meta property="og:description" content="Full-stack developer at Chief Systems, Lethbridge.">' . "\n"
            . '<meta property="og:url" content="https://williamhleucka.com/resume">' . "\n"
            . '<meta property="og:image" content="https://williamhleucka.com/img/share.png">' . "\n"
            . '<meta property="og:image:width" content="1200">' . "\n"
            . '<meta property="og:image:height" content="630">' . "\n"
            . '<meta property="og:image:alt" content="William Hleucka">' . "\n"
            . '<meta name="twitter:card" content="summary_large_image">' . "\n",
            (string) $meta->tags(),
        );
    }

    public function test_tags_and_to_string_are_markup_not_text(): void
    {
        $meta = $this->site->page('Resume', 'About.', '/resume');

        self::assertInstanceOf(HtmlView::class, $meta->tags());
        self::assertSame((string) $meta->tags(), (string) $meta);
    }

    public function test_the_root_path_is_the_site_itself(): void
    {
        $out = (string) $this->site->page('Home', 'Hi.', '/');

        self::assertStringContainsString('<link rel="canonical" href="https://williamhleucka.com/">', $out);
    }

    public function test_title_and_description_are_escaped_once(): void
    {
        $out = (string) $this->site->page('Tom & "Jerry" <b>', "It's <script>x</script> & more", '/x');

        self::assertStringContainsString('<title>Tom &amp; &quot;Jerry&quot; &lt;b&gt; — William Hleucka</title>', $out);
        self::assertStringContainsString('<meta property="og:title" content="Tom &amp; &quot;Jerry&quot; &lt;b&gt;">', $out);
        self::assertStringContainsString('content="It&#039;s &lt;script&gt;x&lt;/script&gt; &amp; more"', $out);
        self::assertStringNotContainsString('&amp;amp;', $out);
    }

    public function test_a_path_with_characters_that_need_escaping_stays_one_url(): void
    {
        $out = (string) $this->site->page('Tag', 'Posts.', '/blog/tag/c"s');

        self::assertStringContainsString('href="https://williamhleucka.com/blog/tag/c&quot;s"', $out);
    }

    public function test_a_twitter_handle_is_printed_when_set(): void
    {
        $site = new SiteMeta('https://example.com', 'Ex', new Image('/i.png', 1, 1, 'Ex'), twitterSite: '@example');

        self::assertStringContainsString('<meta name="twitter:site" content="@example">', (string) $site->page('A', 'B', '/'));
    }

    public function test_no_twitter_handle_prints_no_twitter_site(): void
    {
        self::assertStringNotContainsString('twitter:site', (string) $this->site->page('A', 'B', '/'));
    }

    public function test_an_absolute_image_is_printed_as_given(): void
    {
        $site = new SiteMeta('https://example.com', 'Ex', new Image('https://cdn.example.net/share.png', 1200, 630, 'Ex'));

        self::assertStringContainsString(
            '<meta property="og:image" content="https://cdn.example.net/share.png">',
            (string) $site->page('A', 'B', '/'),
        );
    }

    public function test_the_default_title_format_is_the_title_then_the_site_name(): void
    {
        $site = new SiteMeta('https://example.com', 'Example', new Image('/i.png', 1, 1, 'Ex'));

        self::assertStringStartsWith('<title>About · Example</title>', (string) $site->page('About', 'B', '/about'));
    }

    public function test_a_path_must_start_with_a_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Meta path must start with "/"; got "resume".');

        $this->site->page('Resume', 'About.', 'resume');
    }

    public function test_a_path_cannot_carry_a_query_or_fragment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Meta path cannot carry a query or fragment; got "/blog?page=2". The canonical URL is the page without them.');

        $this->site->page('Blog', 'Posts.', '/blog?page=2');
    }

    public function test_a_protocol_relative_path_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Meta path must start with "/"');

        $this->site->page('X', 'Y', '//evil.example/x');
    }

    public function test_an_article_carries_its_dates_tags_and_image(): void
    {
        $out = (string) $this->site->article(
            title: 'Rewriting this site',
            description: 'Third framework.',
            path: '/blog/rewriting',
            publishedAt: new DateTimeImmutable('2026-10-14T09:30:00-06:00'),
            modifiedAt: new DateTimeImmutable('2026-10-15T10:00:00-06:00'),
            tags: ['code', 'hydra & php'],
            image: new Image('/img/posts/rewrite.jpg', 1600, 900, 'A diff on a screen'),
        );

        self::assertStringContainsString('<meta property="og:type" content="article">', $out);
        self::assertStringNotContainsString('content="website"', $out);
        self::assertStringContainsString('<meta property="og:image" content="https://williamhleucka.com/img/posts/rewrite.jpg">', $out);
        self::assertStringContainsString('<meta property="og:image:width" content="1600">', $out);
        self::assertStringContainsString('<meta property="og:image:alt" content="A diff on a screen">', $out);
        self::assertStringContainsString(
            '<meta name="twitter:card" content="summary_large_image">' . "\n"
            . '<meta property="article:published_time" content="2026-10-14T09:30:00-06:00">' . "\n"
            . '<meta property="article:modified_time" content="2026-10-15T10:00:00-06:00">' . "\n"
            . '<meta property="article:tag" content="code">' . "\n"
            . '<meta property="article:tag" content="hydra &amp; php">' . "\n",
            $out,
        );
    }

    public function test_an_article_without_an_image_or_modified_time_uses_the_default_and_omits_it(): void
    {
        $out = (string) $this->site->article('A', 'B', '/blog/a', new DateTimeImmutable('2026-10-14T09:30:00Z'));

        self::assertStringContainsString('content="https://williamhleucka.com/img/share.png"', $out);
        self::assertStringContainsString('<meta property="article:published_time" content="2026-10-14T09:30:00+00:00">', $out);
        self::assertStringNotContainsString('article:modified_time', $out);
        self::assertStringNotContainsString('article:tag', $out);
    }

    public function test_an_article_path_is_checked_like_a_page_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Meta path must start with "/"; got "blog/a".');

        $this->site->article('A', 'B', 'blog/a', new DateTimeImmutable);
    }

    public function test_with_no_index_adds_robots_and_leaves_the_original_alone(): void
    {
        $meta = $this->site->page('A', 'B', '/a');
        $hidden = $meta->withNoIndex();

        self::assertNotSame($meta, $hidden);
        self::assertStringEndsWith('<meta name="robots" content="noindex">' . "\n", (string) $hidden);
        self::assertStringNotContainsString('robots', (string) $meta);
    }

    public function test_with_feed_adds_an_absolute_alternate_link(): void
    {
        $meta = $this->site->page('A', 'B', '/a');
        $fed = $meta->withFeed('Writing & more', '/feed.xml')->withFeed('Comments', '/comments.xml');

        self::assertStringContainsString(
            '<link rel="alternate" type="application/atom+xml" title="Writing &amp; more" href="https://williamhleucka.com/feed.xml">' . "\n"
            . '<link rel="alternate" type="application/atom+xml" title="Comments" href="https://williamhleucka.com/comments.xml">' . "\n",
            (string) $fed,
        );
        self::assertStringNotContainsString('alternate', (string) $meta);
    }

    public function test_a_feed_path_is_checked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Meta path must start with "/"; got "feed.xml".');

        $this->site->page('A', 'B', '/a')->withFeed('Writing', 'feed.xml');
    }

    public function test_feeds_come_before_noindex_whatever_the_order_they_were_added(): void
    {
        $out = (string) $this->site->page('A', 'B', '/a')->withNoIndex()->withFeed('F', '/f.xml');

        self::assertLessThan(strpos($out, 'robots'), strpos($out, 'alternate'));
    }

    public function test_with_title_replaces_the_title_and_can_skip_the_format(): void
    {
        $meta = $this->site->page('Home', 'B', '/');

        self::assertStringStartsWith('<title>William Hleucka</title>', (string) $meta->withTitle('William Hleucka', format: false));
        self::assertStringContainsString('<meta property="og:title" content="William Hleucka">', (string) $meta->withTitle('William Hleucka', format: false));
        self::assertStringStartsWith('<title>About — William Hleucka</title>', (string) $meta->withTitle('About'));
        self::assertStringStartsWith('<title>Home — William Hleucka</title>', (string) $meta);
    }

    public function test_a_long_description_is_cut_on_a_word_boundary_at_200_characters(): void
    {
        $long = str_repeat('word ', 60); // 300 characters
        $out = (string) $this->site->page('A', $long, '/a');

        preg_match('#<meta name="description" content="([^"]*)">#', $out, $m);
        $description = html_entity_decode($m[1]);

        self::assertLessThanOrEqual(200, mb_strlen($description));
        self::assertStringEndsWith('word…', $description);
        self::assertStringNotContainsString('wor…', $description);
        self::assertStringContainsString('<meta property="og:description" content="' . $m[1] . '">', $out);
    }

    public function test_a_description_of_exactly_200_characters_is_kept_whole(): void
    {
        $exact = str_repeat('a', 199) . 'é';
        $out = (string) $this->site->page('A', $exact, '/a');

        self::assertStringContainsString('content="' . $exact . '"', $out);
    }

    public function test_a_long_word_with_no_space_is_cut_hard(): void
    {
        $out = (string) $this->site->page('A', str_repeat('x', 250), '/a');

        self::assertStringContainsString('content="' . str_repeat('x', 199) . '…"', $out);
    }

    public function test_a_description_is_collapsed_to_one_line(): void
    {
        $out = (string) $this->site->page('A', "  First line.\n\n  Second\tline.  ", '/a');

        self::assertStringContainsString('<meta name="description" content="First line. Second line.">', $out);
    }
}
