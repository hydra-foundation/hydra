<?php

declare(strict_types=1);

namespace Hydra\Http\Attributes;

use Attribute;

/**
 * Declares a group on a controller class: a shared path prefix and/or shared
 * middleware applied to every #[Route] on the class's methods.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RouteGroup
{
    public function __construct(
        public readonly string $prefix = '',
        public readonly array $middleware = [],
    ) {}
}
