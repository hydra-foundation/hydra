<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Hydra\Http\ErrorContext;
use Psr\Http\Message\ResponseInterface;

/**
 * Error renderer interface
 *
 * Turns a caught error into a response body and content type
 */
interface ErrorRendererInterface
{
    public function render(ErrorContext $context): ResponseInterface;
}
