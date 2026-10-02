<?php

declare(strict_types=1);

namespace Hydra\Seo;

use DateTimeInterface;
use Hydra\View\HtmlView;
use Stringable;

/**
 * One page's <head> tags: the title, the description, the canonical URL, and
 * what a link preview reads (Open Graph, Twitter). Made by SiteMeta; printed
 * by the layout with `<?= $meta ?>`. Every value is escaped here, once, so a
 * template never decides it.
 *
 * Immutable: each with*() returns a copy, so a Meta handed to a view cannot
 * be changed by whoever handed it over.
 */
final class Meta implements Stringable
{
    /** Previews show about this much; past it, a description is cut on a word. */
    private const DESCRIPTION_LIMIT = 200;

    private bool $noIndex = false;

    private bool $formatTitle = true;

    /** @var list<array{string, string}> title, path */
    private array $feeds = [];

    /**
     * @internal made by SiteMeta, which checks the path
     *
     * @param list<string> $tags
     */
    public function __construct(
        private readonly SiteMeta $site,
        private string $title,
        private readonly string $description,
        private readonly string $path,
        private readonly ?Image $image = null,
        private readonly ?DateTimeInterface $publishedAt = null,
        private readonly ?DateTimeInterface $modifiedAt = null,
        private readonly array $tags = [],
    ) {}

    /** Keep the page out of search results: the admin, sign-in, a draft. */
    public function withNoIndex(): self
    {
        $copy = clone $this;
        $copy->noIndex = true;

        return $copy;
    }

    /** Announce an Atom feed, so a reader finds it from the page's URL. */
    public function withFeed(string $title, string $path): self
    {
        $copy = clone $this;
        $copy->feeds[] = [$title, SiteMeta::path($path)];

        return $copy;
    }

    /**
     * Another title. Without the format, the <title> is the title alone: the
     * home page, whose title is usually the site's name already.
     */
    public function withTitle(string $title, bool $format = true): self
    {
        $copy = clone $this;
        $copy->title = $title;
        $copy->formatTitle = $format;

        return $copy;
    }

    public function tags(): HtmlView
    {
        $site = $this->site;
        $url = $site->url($this->path);
        $image = $this->image ?? $site->defaultImage;
        $description = self::shorten($this->description);
        $article = $this->publishedAt !== null;

        $lines = [
            '<title>' . self::e($this->formatTitle ? $site->title($this->title) : $this->title) . '</title>',
            self::name('description', $description),
            '<link rel="canonical" href="' . self::e($url) . '">',
            self::property('og:type', $article ? 'article' : 'website'),
            self::property('og:site_name', $site->siteName),
            self::property('og:title', $this->title),
            self::property('og:description', $description),
            self::property('og:url', $url),
            self::property('og:image', $site->url($image->url)),
            self::property('og:image:width', (string) $image->width),
            self::property('og:image:height', (string) $image->height),
            self::property('og:image:alt', $image->alt),
            self::name('twitter:card', 'summary_large_image'),
        ];

        if ($site->twitterSite !== null) {
            $lines[] = self::name('twitter:site', $site->twitterSite);
        }

        if ($this->publishedAt !== null) {
            $lines[] = self::property('article:published_time', $this->publishedAt->format(DATE_ATOM));
        }

        if ($this->modifiedAt !== null) {
            $lines[] = self::property('article:modified_time', $this->modifiedAt->format(DATE_ATOM));
        }

        foreach ($this->tags as $tag) {
            $lines[] = self::property('article:tag', $tag);
        }

        foreach ($this->feeds as [$title, $path]) {
            $lines[] = '<link rel="alternate" type="application/atom+xml" title="' . self::e($title)
                . '" href="' . self::e($site->url($path)) . '">';
        }

        if ($this->noIndex) {
            $lines[] = self::name('robots', 'noindex');
        }

        return new HtmlView(implode("\n", $lines) . "\n");
    }

    public function __toString(): string
    {
        return (string) $this->tags();
    }

    /**
     * One line, at most DESCRIPTION_LIMIT characters: front matter is written
     * without counting, and a preview cuts mid-word where this cuts on one.
     */
    private static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= self::DESCRIPTION_LIMIT) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_LIMIT - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space === false ? $cut : mb_substr($cut, 0, $space)) . '…';
    }

    private static function name(string $name, string $content): string
    {
        return '<meta name="' . $name . '" content="' . self::e($content) . '">';
    }

    private static function property(string $property, string $content): string
    {
        return '<meta property="' . $property . '" content="' . self::e($content) . '">';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
