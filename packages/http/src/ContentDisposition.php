<?php

declare(strict_types=1);

namespace Hydra\Http;

use InvalidArgumentException;

/**
 * The one place a filename is quoted into a Content-Disposition header.
 *
 * Both spellings of the name are sent. The quoted one is what every client
 * has always read, and it carries only characters a header can hold
 * literally: a name is attacker-influenced often enough (a row's title, an
 * upload's name) that the quote and the newline inside it are worth spending
 * a transliteration on. filename* is RFC 5987 and carries the name as written
 * for the clients that prefer it, which is every current one.
 */
final class ContentDisposition
{
    public const INLINE = 'inline';
    public const ATTACHMENT = 'attachment';

    private function __construct() {}

    /** The header value; with no filename, the disposition alone. */
    public static function header(string $disposition, ?string $filename = null): string
    {
        if ($disposition !== self::INLINE && $disposition !== self::ATTACHMENT) {
            throw new InvalidArgumentException(sprintf(
                'A disposition is "inline" or "attachment", not "%s".',
                $disposition,
            ));
        }

        if ($filename === null || $filename === '') {
            return $disposition;
        }

        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename), '_');

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $ascii === '' ? 'download' : $ascii,
            rawurlencode($filename),
        );
    }
}
