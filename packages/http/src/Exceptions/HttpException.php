<?php

declare(strict_types=1);

namespace Hydra\Http\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An exception that maps to an HTTP status code
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers Response headers this error implies (e.g. Allow). */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
