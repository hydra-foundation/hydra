<?php

declare(strict_types=1);

namespace Hydra\Http\Exceptions;

/**
 * A 503 the application chose to send, with `Retry-After` when it knows when
 * to come back. An HttpException, so its message is shown to the client and it
 * renders as a page, a JSON body or an htmx fragment like every other refusal.
 */
final class ServiceUnavailableException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable', ?int $retryAfter = null)
    {
        parent::__construct(
            503,
            $message,
            $retryAfter === null ? [] : ['Retry-After' => (string) max(1, $retryAfter)],
        );
    }
}
