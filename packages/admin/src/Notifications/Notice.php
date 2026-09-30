<?php

declare(strict_types=1);

namespace Hydra\Admin\Notifications;

use InvalidArgumentException;

/**
 * Something a user should know, as the bell shows it: a title, perhaps a
 * line more, and perhaps where to go. Stored as written, so it says what it
 * said when it was sent.
 *
 * Plain text throughout: everything is escaped where it is drawn. The link is
 * a path in this application or a web address, never a script: a notice
 * reaches whoever it names, and a link in it is followed on trust.
 */
final readonly class Notice
{
    public const MAX_TITLE = 255;
    public const MAX_BODY = 2000;
    public const MAX_URL = 2048;

    public function __construct(
        public string $title,
        public ?string $body = null,
        public ?string $url = null,
        /** A stable key for what kind of notice this is, such as two_factor.enabled. */
        public string $kind = 'notice',
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('A notice needs a title.');
        }

        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new InvalidArgumentException(sprintf('A notice title is at most %d characters.', self::MAX_TITLE));
        }

        if ($body !== null && mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidArgumentException(sprintf('A notice body is at most %d characters.', self::MAX_BODY));
        }

        if ($url !== null && !self::isLink($url)) {
            throw new InvalidArgumentException("\"{$url}\" is not a link a notice may carry: use a path starting with / or an http(s) address, at most " . self::MAX_URL . ' characters.');
        }

        if (preg_match('/^[a-z0-9_.-]{1,64}$/D', $kind) !== 1) {
            throw new InvalidArgumentException("\"{$kind}\" is not a notice kind: use a-z, 0-9, _, . and -, at most 64 characters.");
        }
    }

    private static function isLink(string $url): bool
    {
        if (strlen($url) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        // A path in this application, but not //host, which a browser reads
        // as an address on another site.
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        }

        return preg_match('~^https?://[^/\s]+~i', $url) === 1;
    }
}
