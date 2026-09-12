<?php

declare(strict_types=1);

namespace Hydra\Csrf\Exceptions;

use Hydra\Http\Exceptions\HttpException;
use Throwable;

/**
 * An unsafe request arrived without a valid CSRF token: HTTP 403
 */
final class TokenMismatchException extends HttpException
{
    public function __construct(string $message = 'CSRF token mismatch.', ?Throwable $previous = null)
    {
        parent::__construct(403, $message, [], $previous);
    }
}
