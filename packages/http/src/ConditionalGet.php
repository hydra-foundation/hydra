<?php

declare(strict_types=1);

namespace Hydra\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether a request already holds the page a response describes (RFC 9110
 * §13): `If-None-Match` against the ETag, weakly, and only when there is none,
 * `If-Modified-Since` against the last modification, to the second. Only a
 * GET or a HEAD can be answered with a 304.
 */
final class ConditionalGet
{
    /**
     * @param string $etag the response's ETag, '' when it has none
     * @param string $lastModified its Last-Modified as an HTTP date, '' when none
     */
    public static function fresh(ServerRequestInterface $request, string $etag, string $lastModified): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        if ($request->hasHeader('If-None-Match')) {
            return $etag !== '' && self::matches($request->getHeaderLine('If-None-Match'), $etag);
        }

        $since = self::date($request->getHeaderLine('If-Modified-Since'));
        $modified = self::date($lastModified);

        return $since !== null && $modified !== null && $modified <= $since;
    }

    /** Weak comparison: `W/"x"` and `"x"` are the same tag. */
    private static function matches(string $header, string $etag): bool
    {
        if ($header === '*') {
            return true;
        }

        $opaque = self::opaque($etag);

        foreach (explode(',', $header) as $candidate) {
            if (self::opaque(trim($candidate)) === $opaque) {
                return true;
            }
        }

        return false;
    }

    private static function opaque(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(HttpCache::DATE, $value, new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
