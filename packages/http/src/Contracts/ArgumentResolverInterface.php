<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Argument resolver interface
 *
 * Resolves the positional arguments to invoke a route target with, by
 * inspecting the target's signature
 */
interface ArgumentResolverInterface
{
    public function resolve(callable $target, ServerRequestInterface $request, array $routeParams): array;
}
