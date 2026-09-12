<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Psr\Http\Message\ResponseInterface;

/**
 * The last step of a request: getting a PSR-7 response out to the client. Behind
 * a seam so the SAPI's global output functions stay out of the kernel and can be
 * replaced under test or by a long-running server.
 */
interface EmitterInterface
{
    /** Status line, headers, then body. */
    public function emit(ResponseInterface $response): void;
}
