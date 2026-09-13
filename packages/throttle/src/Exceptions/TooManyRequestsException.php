<?php

declare(strict_types=1);

namespace Hydra\Throttle\Exceptions;

use Hydra\Http\Exceptions\HttpException;

/**
 * The refusal a spent budget produces. It carries `Retry-After`, so the client
 * is told when to come back rather than left to guess, and it is an
 * HttpException so the error handler renders it the same way as every other
 * refusal: as a page, a JSON body or an htmx fragment, whichever was asked for.
 */
final class TooManyRequestsException extends HttpException
{
    public function __construct(
        private readonly int $retryAfter,
        string $message = 'Too many requests. Try again shortly.',
    ) {
        parent::__construct(429, $message, ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}
