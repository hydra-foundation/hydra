<?php

declare(strict_types=1);

namespace Hydra\Http\Exceptions;

use Throwable;

/** The client sent a request the server cannot parse: HTTP 400. */
final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }
}
