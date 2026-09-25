<?php

declare(strict_types=1);

namespace Hydra\Auth\Exceptions;

use Hydra\Http\Exceptions\HttpException;
use Throwable;

/**
 * A request reached a guarded route without an authenticated user: HTTP 401
 */
final class AuthenticationException extends HttpException
{
    /** @param array<string, string> $headers such as the WWW-Authenticate a refused bearer token earns */
    public function __construct(string $message = 'Unauthenticated.', ?Throwable $previous = null, array $headers = [])
    {
        parent::__construct(401, $message, $headers, $previous);
    }
}
