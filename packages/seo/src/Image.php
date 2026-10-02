<?php

declare(strict_types=1);

namespace Hydra\Seo;

use InvalidArgumentException;

/**
 * A share image: what a link preview shows. The size is required because
 * the services that draw previews lay the card out before fetching the
 * file, and some skip an image they cannot size.
 */
final class Image
{
    /**
     * @param string $url a path from the site root ("/img/share.png"), made
     *                    absolute on the site's base URL, or an http(s) URL
     *                    on another host (a CDN), printed as given
     */
    public function __construct(
        public readonly string $url,
        public readonly int $width,
        public readonly int $height,
        public readonly string $alt,
    ) {
        $path = str_starts_with($url, '/') && !str_starts_with($url, '//');
        if (!$path && preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException(sprintf('An image is a path starting with "/" or an http(s) URL; got "%s".', $url));
        }

        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException(sprintf('An image needs a positive width and height; got %dx%d for "%s".', $width, $height, $url));
        }
    }
}
