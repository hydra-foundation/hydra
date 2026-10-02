<?php

declare(strict_types=1);

namespace Hydra\Seo;

use Hydra\View\HtmlView;
use Stringable;

/**
 * One page's <head> tags: the title, the description, the canonical URL, and
 * what a link preview reads (Open Graph, Twitter). Made by SiteMeta; printed
 * by the layout with `<?= $meta ?>`. Every value is escaped here, once, so a
 * template never decides it.
 */
final class Meta implements Stringable
{
    /** @internal made by SiteMeta, which checks the path */
    public function __construct(
        private readonly SiteMeta $site,
        private readonly string $title,
        private readonly string $description,
        private readonly string $path,
    ) {}

    public function tags(): HtmlView
    {
        $site = $this->site;
        $url = $site->url($this->path);
        $image = $site->defaultImage;

        $lines = [
            '<title>' . self::e($site->title($this->title)) . '</title>',
            self::name('description', $this->description),
            '<link rel="canonical" href="' . self::e($url) . '">',
            self::property('og:type', 'website'),
            self::property('og:site_name', $site->siteName),
            self::property('og:title', $this->title),
            self::property('og:description', $this->description),
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

        return new HtmlView(implode("\n", $lines) . "\n");
    }

    public function __toString(): string
    {
        return (string) $this->tags();
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
