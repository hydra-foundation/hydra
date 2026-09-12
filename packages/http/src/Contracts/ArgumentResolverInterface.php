<?php

declare(strict_types=1);

namespace Hydra\Http\Contracts;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the positional arguments to invoke a route target with, by
 * inspecting the target's signature
 */
interface ArgumentResolverInterface
{
    /**
     * @param array<string, string> $routeParams
     * @return list<mixed>
     */
    public function resolve(callable $target, ServerRequestInterface $request, array $routeParams): array;
}
