<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Server request provider interface
 *
 * Builds the incoming server request from the current environment
 */
interface ServerRequestProviderInterface
{
    public function fromGlobals(): ServerRequestInterface;
}
