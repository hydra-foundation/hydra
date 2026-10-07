<?php

declare(strict_types=1);

namespace Hydra\CommonMark\Tests\Unit;

use DateTimeImmutable;
use Hydra\CommonMark\CommonMarkRenderer;
use Hydra\CommonMark\YamlFrontMatter;
use Hydra\View\InvalidFrontMatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A content file read in one go: what its front matter says, and its body. */
#[CoversClass(CommonMarkRenderer::class)]
#[CoversClass(YamlFrontMatter::class)]
#[CoversClass(InvalidFrontMatter::class)]
final class FrontMatterTest extends TestCase
{
    private CommonMarkRenderer $markdown;

    protected function setUp(): void
    {
        $this->markdown = new CommonMarkRenderer;
    }

    public function test_a_file_without_front_matter_has_none(): void
    {
        $document = $this->markdown->parse("# Title\n\nBody.");

        $this->assertSame([], $document->meta);
        $this->assertStringContainsString('<h1', (string) $document->html);
    }

    public function test_front_matter_with_nothing_but_a_comment_is_none(): void
    {
        $document = $this->markdown->parse("---\n# title: not yet\n---\nBody.");

        $this->assertSame([], $document->meta);
        $this->assertStringContainsString('Body.', (string) $document->html);
    }

    public function test_the_front_matter_is_read_and_left_out_of_the_body(): void
    {
        $document = $this->markdown->parse(<<<MD
            ---
            title: First post
            tags: [code, hydra]
            draft: true
            ---
            Body text.
            MD);

        $this->assertSame('First post', $document->meta['title']);
        $this->assertSame(['code', 'hydra'], $document->meta['tags']);
        $this->assertTrue($document->meta['draft']);
        $this->assertStringNotContainsString('First post', (string) $document->html);
        $this->assertStringContainsString('Body text.', (string) $document->html);
    }

    public function test_dates_come_back_as_dates_not_timestamps(): void
    {
        $meta = $this->markdown->parse(<<<MD
            ---
            date: 2026-10-10
            updated: 2026-10-11T09:30:00-06:00
            history: [2026-01-01]
            label: "2026-10-10"
            ---
            x
            MD)->meta;

        $this->assertInstanceOf(DateTimeImmutable::class, $meta['date']);
        $this->assertSame('2026-10-10', $meta['date']->format('Y-m-d'));
        $this->assertInstanceOf(DateTimeImmutable::class, $meta['updated']);
        $this->assertSame('2026-10-11T09:30:00-06:00', $meta['updated']->format(DATE_ATOM));
        $this->assertInstanceOf(DateTimeImmutable::class, $meta['history'][0]);
        $this->assertSame('2026-10-10', $meta['label'], 'a quoted date is the text it says');
    }

    public function test_to_html_leaves_the_front_matter_out_too(): void
    {
        $html = (string) $this->markdown->toHtml("---\ntitle: Hidden\n---\nShown.");

        $this->assertStringNotContainsString('Hidden', $html);
        $this->assertStringContainsString('Shown.', $html);
    }

    public function test_front_matter_values_are_data_never_markup(): void
    {
        $document = $this->markdown->parse("---\ntitle: \"<script>alert(1)</script>\"\n---\nx");

        $this->assertSame('<script>alert(1)</script>', $document->meta['title'], 'the caller escapes it where it prints it');
        $this->assertStringNotContainsString('script', (string) $document->html);
    }

    /** @return iterable<string, array{string}> */
    public static function broken(): iterable
    {
        yield 'malformed yaml' => ["---\ntitle: [unclosed\n---\nx"];
        yield 'a list, not a mapping' => ["---\n- one\n- two\n---\nx"];
        yield 'a bare string' => ["---\njust words\n---\nx"];
    }

    #[DataProvider('broken')]
    public function test_bad_front_matter_fails_loudly(string $source): void
    {
        $this->expectException(InvalidFrontMatter::class);

        $this->markdown->parse($source);
    }

    public function test_a_parsed_file_is_untrusted_unless_it_says_so(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', (string) $this->markdown->parse('<b>x</b>')->html);
        $this->assertStringContainsString('<b>x</b>', (string) $this->markdown->parse('<b>x</b>', trusted: true)->html);
    }

    public function test_the_failure_says_what_and_where(): void
    {
        try {
            $this->markdown->parse("---\ntitle: ok\ntags: [unclosed\n---\nx");
            $this->fail('Malformed front matter must not parse.');
        } catch (InvalidFrontMatter $e) {
            $this->assertMatchesRegularExpression('/^The front matter .*line \d+/is', $e->getMessage());
        }
    }
}
