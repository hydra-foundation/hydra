<?php

declare(strict_types=1);

namespace Hydra\Auth\Exceptions;

use Hydra\Http\Exceptions\HttpException;
use Throwable;

/**
 * Authentication exception
 *
 * A request reached a guarded route without an authenticated user: HTTP 401
 */
final class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'Unauthenticated.', ?Throwable $previous = null)
    {
        parent::__construct(401, $message, [], $previous);
    }
}
