<?php

declare(strict_types=1);

namespace Hydra\Seo\Tests\Unit;

use Hydra\Seo\Image;
use Hydra\Seo\Meta;
use Hydra\Seo\SiteMeta;
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
}
