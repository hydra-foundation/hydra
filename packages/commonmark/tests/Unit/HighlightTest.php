<?php

declare(strict_types=1);

namespace Hydra\CommonMark\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Hydra\CommonMark\CommonMarkRenderer;
use Hydra\CommonMark\MarkdownOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Fenced code coloured on the server: no script on the page, nothing for the CSP to allow. */
#[CoversClass(CommonMarkRenderer::class)]
#[CoversClass(MarkdownOptions::class)]
final class HighlightTest extends TestCase
{
    public function test_a_language_it_knows_is_highlighted(): void
    {
        $xpath = $this->render("```php\n<?php \$a = 1;\n```");

        $this->assertSame(1, $xpath->query('//pre[@data-lang="php"]')?->length);
        $this->assertGreaterThan(0, $xpath->query('//pre//span[contains(@class, "hl-")]')?->length);
    }

    public function test_a_language_it_doesnt_know_is_plain_escaped_code(): void
    {
        $xpath = $this->render("```lua\nprint(\"<b>hi</b>\")\n```");

        $this->assertSame(0, $xpath->query('//pre//span')?->length);
        $this->assertSame(0, $xpath->query('//pre//b')?->length);
        $this->assertStringContainsString('print("<b>hi</b>")', (string) $xpath->query('//pre')?->item(0)?->textContent);
    }

    public function test_markup_inside_code_stays_text_whatever_the_language(): void
    {
        foreach (['php', 'html', 'javascript', ''] as $language) {
            $xpath = $this->render("```{$language}\n<script>alert(1)</script><img src=x onerror=alert(1)>\n```", trusted: true);

            $this->assertSame(0, $xpath->query('//script | //img | //@*[starts-with(name(), "on")]')?->length, "language '{$language}'");
        }
    }

    public function test_highlighting_can_be_left_to_the_page(): void
    {
        $xpath = $this->render("```php\n\$a = 1;\n```", options: new MarkdownOptions(highlight: false));

        $this->assertSame(1, $xpath->query('//pre/code[@class="language-php"]')?->length);
        $this->assertSame(0, $xpath->query('//pre//span')?->length);
    }

    private function render(string $markdown, bool $trusted = false, ?MarkdownOptions $options = null): DOMXPath
    {
        $html = (string) (new CommonMarkRenderer($options ?? new MarkdownOptions))->toHtml($markdown, $trusted);

        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        return new DOMXPath($document);
    }
}
