<?php

declare(strict_types=1);

namespace Hydra\CommonMark\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Hydra\CommonMark\CommonMarkRenderer;
use Hydra\CommonMark\MarkdownOptions;
use Hydra\View\HtmlView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Markdown to HTML a public page can print. The safety cases assert on the
 * parsed document, never on strings: what matters is whether a browser would
 * find a script, a handler or a dangerous link in it, however it is spelled.
 */
#[CoversClass(CommonMarkRenderer::class)]
#[CoversClass(MarkdownOptions::class)]
final class CommonMarkRendererTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        yield 'a script tag' => ['<script>alert(1)</script>'];
        yield 'an image with a handler' => ['<img src="x" onerror="alert(1)">'];
        yield 'an anchor with a javascript href' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'an iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'a javascript link' => ['[x](javascript:alert(1))'];
        yield 'a javascript link in odd case' => ['[x](JaVaScRiPt:alert(1))'];
        yield 'a javascript link with an entity tab' => ['[x](java&#09;script:alert(1))'];
        yield 'a javascript link, percent-encoded' => ['[x](%6Aavascript:alert(1))'];
        yield 'a javascript link with a literal tab' => ["[x](<java\tscript:alert(1)>)"];
        yield 'a javascript link with an entity newline' => ['[x](java&#10;script:alert(1))'];
        yield 'a javascript link behind a leading space entity' => ['[x](&#32;javascript:alert(1))'];
        yield 'a vbscript link' => ['[x](vbscript:msgbox(1))'];
        yield 'a file link' => ['[x](file:///etc/passwd)'];
        yield 'a data html link' => ['[x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'];
        yield 'a javascript image' => ['![x](javascript:alert(1))'];
        yield 'a javascript autolink' => ['<javascript:alert(1)>'];
        yield 'a reference-style javascript link' => ["[x][r]\n\n[r]: javascript:alert(1)"];
        yield 'a handler smuggled into a title' => ['[x](https://ok.example "a\" onmouseover=\"alert(1)")'];
        yield 'a style tag' => ['<style>body{display:none}</style>'];
        yield 'a form' => ['<form action="https://evil.example"><button>go</button></form>'];
    }

    #[DataProvider('hostile')]
    public function test_untrusted_input_never_yields_runnable_markup(string $markdown): void
    {
        $this->assertHarmless((string) $this->renderer()->toHtml($markdown));
    }

    /** @return iterable<string, array{string}> */
    public static function linksOnly(): iterable
    {
        foreach (self::hostile() as $name => [$markdown]) {
            if (!str_starts_with($markdown, '<')) {
                yield $name => [$markdown];
            }
        }
    }

    /** Trust lets raw HTML through, never an unsafe link. */
    #[DataProvider('linksOnly')]
    public function test_trusted_input_still_refuses_unsafe_links(string $markdown): void
    {
        $this->assertHarmless((string) $this->renderer()->toHtml($markdown, trusted: true));
    }

    public function test_untrusted_raw_html_is_shown_as_text(): void
    {
        $html = (string) $this->renderer()->toHtml('Hello <b>there</b>');

        $this->assertStringContainsString('&lt;b&gt;there&lt;/b&gt;', $html);
    }

    public function test_trusted_raw_html_passes(): void
    {
        $html = (string) $this->renderer()->toHtml("<details><summary>More</summary>\n\nHidden.\n\n</details>", trusted: true);

        $this->assertSame(1, $this->query($html, '//details/summary')->length);
    }

    public function test_trusted_input_still_cannot_run_a_script(): void
    {
        $this->assertHarmless((string) $this->renderer()->toHtml('<script>alert(1)</script>', trusted: true));
    }

    public function test_the_result_is_html_a_view_prints_as_it_is(): void
    {
        $this->assertInstanceOf(HtmlView::class, $this->renderer()->toHtml('x'));
    }

    public function test_github_flavour_is_on(): void
    {
        $html = (string) $this->renderer()->toHtml(<<<MD
            | a | b |
            |---|---|
            | 1 | 2 |

            ~~gone~~

            - [x] done
            - [ ] not yet

            See https://example.com today.
            MD);

        $this->assertSame(1, $this->query($html, '//table//td[.="2"]')->length);
        $this->assertSame(1, $this->query($html, '//del[.="gone"]')->length);
        $this->assertSame(2, $this->query($html, '//li/input[@type="checkbox"]')->length);
        $this->assertSame(1, $this->query($html, '//a[@href="https://example.com"]')->length);
    }

    public function test_headings_get_ids_a_reader_can_link_to(): void
    {
        $html = (string) $this->renderer()->toHtml("## Getting started\n\n## Getting started\n\n## <b>Odd</b> & \"quoted\"");

        $ids = array_map(
            static fn (\DOMNode $h): string => $h instanceof DOMElement ? $h->getAttribute('id') : '',
            iterator_to_array($this->query($html, '//h2')),
        );

        $this->assertSame('getting-started', $ids[0]);
        $this->assertCount(3, array_unique($ids), 'two headings with the same text still get two ids');
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $ids[2]);
        $this->assertSame(0, $this->query($html, '//h2/a')->length, 'no permalink symbol is inserted');
    }

    public function test_headings_can_go_without_ids(): void
    {
        $html = (string) $this->renderer(new MarkdownOptions(headingIds: false))->toHtml('## Plain');

        $heading = $this->query($html, '//h2')->item(0);
        $this->assertInstanceOf(DOMElement::class, $heading);
        $this->assertFalse($heading->hasAttribute('id'));
    }

    public function test_an_external_link_cannot_reach_back(): void
    {
        $html = (string) $this->renderer(new MarkdownOptions(internalHosts: ['williamhleucka.com']))->toHtml(
            '[out](https://elsewhere.example) [home](https://williamhleucka.com/blog) [here](/contact)',
        );

        $this->assertSame('noopener noreferrer', $this->link($html, 'out')->getAttribute('rel'));
        $this->assertSame('', $this->link($html, 'home')->getAttribute('rel'));
        $this->assertSame('', $this->link($html, 'here')->getAttribute('rel'));
        $this->assertSame('', $this->link($html, 'out')->getAttribute('target'), 'same tab');
    }

    public function test_nesting_stops_at_the_limit(): void
    {
        $html = (string) $this->renderer(new MarkdownOptions(maxNesting: 3))->toHtml(str_repeat('> ', 50) . 'deep');

        $this->assertLessThanOrEqual(3, $this->query($html, '//blockquote')->length);
        $this->assertStringContainsString('deep', $html);
    }

    public function test_the_limits_are_checked_where_they_are_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MarkdownOptions(maxNesting: 0);
    }

    /** @return iterable<string, array{string}> */
    public static function runnable(): iterable
    {
        yield 'a tab inside the scheme' => ['<a href="java&#9;script:alert(1)">x</a>'];
        yield 'a leading space' => ['<a href=" javascript:alert(1)">x</a>'];
        yield 'an upper-case scheme' => ['<a href="JAVASCRIPT:alert(1)">x</a>'];
        yield 'a handler' => ['<p onclick="alert(1)">x</p>'];
        yield 'a script' => ['<script>alert(1)</script>'];
    }

    /** The checker above is the whole test; this keeps it honest. */
    #[DataProvider('runnable')]
    public function test_the_safety_check_catches_what_a_browser_would_run(string $html): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertHarmless($html);
    }

    private function renderer(?MarkdownOptions $options = null): CommonMarkRenderer
    {
        return new CommonMarkRenderer($options ?? new MarkdownOptions);
    }

    /**
     * No element that runs or loads code, no event handler, and no URL
     * attribute whose scheme would run or smuggle anything. Image data URIs
     * are the one data: CommonMark allows, and they can't run script.
     */
    private function assertHarmless(string $html): void
    {
        foreach (['script', 'iframe', 'object', 'embed', 'style', 'form', 'base', 'meta', 'link'] as $tag) {
            $this->assertSame(0, $this->query($html, "//{$tag}")->length, "<{$tag}> in: {$html}");
        }

        foreach ($this->query($html, '//@*') as $attribute) {
            $name = strtolower($attribute->nodeName);
            $this->assertStringStartsNotWith('on', $name, "handler {$name} in: {$html}");

            if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)) {
                // As a browser reads it (WHATWG URL): entities already decoded by
                // the parser, tabs and newlines removed anywhere, leading controls
                // and spaces trimmed. Percent-escapes are not decoded: "%6A" can't
                // be part of a scheme, so it makes a relative path, not a script.
                $url = strtolower(ltrim((string) preg_replace('/[\t\n\r]/', '', (string) $attribute->nodeValue), "\x00..\x20"));
                $this->assertDoesNotMatchRegularExpression('/^(javascript|vbscript|file|data):/', $url, "{$name}={$url} in: {$html}");
            }
        }
    }

    private function link(string $html, string $text): DOMElement
    {
        $node = $this->query($html, "//a[.='{$text}']")->item(0);
        $this->assertInstanceOf(DOMElement::class, $node);

        return $node;
    }

    /** @return \DOMNodeList<\DOMNode> */
    private function query(string $html, string $xpath): \DOMNodeList
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        $result = (new DOMXPath($document))->query($xpath);
        $this->assertNotFalse($result);

        return $result;
    }
}
