<?php

declare(strict_types=1);

namespace Hydra\Http\Exceptions;

use Throwable;

/**
 * The path matched a route but not for this HTTP method: HTTP 405
 */
final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed Methods registered for the matched path. */
    public function __construct(array $allowed, ?Throwable $previous = null)
    {
        $allow = implode(', ', array_values(array_unique($allowed)));

        parent::__construct(405, 'Method Not Allowed', ['Allow' => $allow], $previous);
    }
}
