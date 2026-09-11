<?php

declare(strict_types=1);

namespace Hydra\Http\Exceptions;

use Throwable;

/** No route (or resource) matched the request: HTTP 404. */
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
