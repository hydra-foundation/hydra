<?php

declare(strict_types=1);

namespace Hydra\View\Contracts;

use Hydra\View\Document;
use Hydra\View\HtmlView;
use Hydra\View\InvalidFrontMatter;

/**
 * Markdown to HTML that a page can print as it is. Untrusted is the default:
 * raw HTML in the source comes out as text, so a visitor's `<script>` is
 * shown rather than run. Trust is for content the site's owner wrote, and
 * only lets raw HTML through; an unsafe link is refused either way.
 */
interface MarkdownInterface
{
    public function toHtml(string $markdown, bool $trusted = false): HtmlView;

    /**
     * A content file: its front matter, and its body as HTML.
     *
     * @throws InvalidFrontMatter when the front matter is malformed, or not a mapping
     */
    public function parse(string $source, bool $trusted = false): Document;
}
