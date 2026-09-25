<?php

declare(strict_types=1);

namespace Hydra\Admin\Updates;

use Hydra\Admin\Contracts\ReleaseFeedInterface;
use JsonException;

/**
 * A plain GET of the static file release.sh publishes. Nothing about the
 * installation goes with it: no version, no host, no query string.
 */
final class HttpReleaseFeed implements ReleaseFeedInterface
{
    public const URL = 'https://hydra.williamhleucka.com/version.json';

    /** The http wrapper spends this on the connect as well as the read. */
    private const TIMEOUT = 2.0;

    private const MAX_BYTES = 65536;

    public function __construct(private readonly string $url = self::URL) {}

    public function fetch(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'header' => "Accept: application/json\r\n",
            ],
        ]);

        $body = @file_get_contents($this->url, false, $context, 0, self::MAX_BYTES);

        if ($body === false) {
            return null;
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
