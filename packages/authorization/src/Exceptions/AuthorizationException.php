<?php

declare(strict_types=1);

namespace Hydra\Authorization\Exceptions;

use Hydra\Http\Exceptions\HttpException;
use Throwable;

/**
 * Authorization exception
 *
 * The authenticated user is not allowed to perform the attempted action: HTTP 403.
 */
final class AuthorizationException extends HttpException
{
    public function __construct(string $message = 'This action is unauthorized.', ?Throwable $previous = null)
    {
        parent::__construct(403, $message, [], $previous);
    }
}
