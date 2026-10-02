<?php

declare(strict_types=1);

namespace Hydra\Seo;

use InvalidArgumentException;

/**
 * The two shapes every builder here takes, checked one way. A crawler, a
 * preview or a feed reader cannot resolve a relative URL, so a builder is
 * given a base URL and paths, and makes every URL it prints itself.
 *
 * @internal
 */
final class Url
{
    /** Scheme and host (and a port), nothing after: https://example.com */
    public static function base(string $url, string $owner): string
    {
        if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#iD', $url) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s baseUrl must look like https://example.com (no path, no trailing slash); got "%s".',
                $owner,
                $url,
            ));
        }

        return $url;
    }

    /**
     * A path the site serves, from its root, without a query or fragment: it
     * names the page itself, which is what a canonical URL, a sitemap entry
     * or a feed id has to be.
     */
    public static function path(string $path, string $what): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException(sprintf('%s must start with "/"; got "%s".', $what, $path));
        }

        if (strpbrk($path, '?#') !== false) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot carry a query or fragment; got "%s". The canonical URL is the page without them.',
                $what,
                $path,
            ));
        }

        return $path;
    }
}
