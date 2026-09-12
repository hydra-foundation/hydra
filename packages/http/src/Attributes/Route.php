<?php

declare(strict_types=1);

namespace Hydra\Http\Attributes;

use Attribute;

/**
 * Declares a route on a controller method. Repeatable, so one method can serve
 * several paths or verbs.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param list<string>|string $methods
     * @param list<class-string> $middleware
     */
    public function __construct(
        public readonly string $path,
        array|string $methods = ['GET'],
        public readonly array $middleware = [],
    ) {
        $this->methods = array_map(strtoupper(...), (array) $methods);
    }
}
