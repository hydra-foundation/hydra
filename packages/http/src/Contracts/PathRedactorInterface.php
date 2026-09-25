<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The request's path as it may be written down: a log line, a fault report.
 */
interface PathRedactorInterface
{
    /**
     * The path with every secret segment replaced by its {placeholder}, or the
     * path unchanged when nothing in it is secret
     */
    public function redact(ServerRequestInterface $request): string;
}
