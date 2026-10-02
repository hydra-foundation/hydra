<?php

declare(strict_types=1);

namespace Hydra\Seo;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * What every page of a site shares: where it lives, what it is called, and
 * the image a link preview falls back to. Built once, in a provider; a
 * controller asks it for the Meta of one page.
 */
final class SiteMeta
{
    /**
     * @param string $baseUrl     scheme and host, e.g. https://example.com: every
     *                            URL a page prints is built on it, because a link
     *                            preview or a crawler cannot resolve a relative one
     * @param string $titleFormat how a page title becomes the <title>: %s is the page's
     *                            title and {site} the site name
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $siteName,
        public readonly Image $defaultImage,
        public readonly ?string $twitterSite = null,
        public readonly string $titleFormat = '%s · {site}',
    ) {
        if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#iD', $baseUrl) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'SiteMeta baseUrl must look like https://example.com (no path, no trailing slash); got "%s".',
                $baseUrl,
            ));
        }

        if (!str_contains($titleFormat, '%s')) {
            throw new InvalidArgumentException(sprintf(
                'titleFormat must contain %%s, where the page title goes; got "%s".',
                $titleFormat,
            ));
        }
    }

    /** An ordinary page: og:type website, the default image. */
    public function page(string $title, string $description, string $path): Meta
    {
        return new Meta($this, $title, $description, self::path($path));
    }

    /**
     * A post: og:type article, its dates and tags, and its own image when it
     * has one, the site's default when not.
     *
     * @param list<string> $tags
     */
    public function article(
        string $title,
        string $description,
        string $path,
        DateTimeInterface $publishedAt,
        ?DateTimeInterface $modifiedAt = null,
        array $tags = [],
        ?Image $image = null,
    ): Meta {
        return new Meta($this, $title, $description, self::path($path), $image, $publishedAt, $modifiedAt, $tags);
    }

    /** The <title> for a page title, in this site's format. */
    public function title(string $title): string
    {
        // One pass, so a title that happens to contain {site} is left alone.
        return strtr($this->titleFormat, ['%s' => $title, '{site}' => $this->siteName]);
    }

    /** An absolute URL for a path from the site root, or an absolute URL as given. */
    public function url(string $pathOrUrl): string
    {
        return str_starts_with($pathOrUrl, '/') ? $this->baseUrl . $pathOrUrl : $pathOrUrl;
    }

    /**
     * A path this site serves, from its root: it becomes a canonical or feed
     * URL, so it is the page itself, without a query or fragment.
     *
     * @internal Meta checks a feed's path with it too
     */
    public static function path(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException(sprintf('Meta path must start with "/"; got "%s".', $path));
        }

        if (strpbrk($path, '?#') !== false) {
            throw new InvalidArgumentException(sprintf(
                'Meta path cannot carry a query or fragment; got "%s". The canonical URL is the page without them.',
                $path,
            ));
        }

        return $path;
    }
}
