<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Attributes\Route as RouteAttribute;
use Hydra\Http\Attributes\RouteGroup as RouteGroupAttribute;
use ReflectionClass;
use ReflectionMethod;

/**
 * Route scanner
 *
 * Reflects over controller classes and turns their #[Route] attributes into a
 * plain, cacheable list of route definitions.
 */
final class RouteScanner
{
    public function scan(iterable $controllers): array
    {
        $routes = [];

        foreach ($controllers as $class) {
            $reflection = new ReflectionClass($class);
            $group = $this->group($reflection);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic()) {
                    continue;
                }

                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $route = $attribute->newInstance();

                    foreach ($route->methods as $verb) {
                        $routes[] = [
                            'method' => $verb,
                            'path' => $this->prefix($group?->prefix ?? '', $route->path),
                            'handler' => [$class, $method->getName()],
                            // Group middleware runs outermost, before the method's own.
                            'middleware' => [...($group?->middleware ?? []), ...$route->middleware],
                        ];
                    }
                }
            }
        }

        return $routes;
    }

    /**
     * The optional class-level group declaration, or null when the controller isn't grouped.
     */
    private function group(ReflectionClass $reflection): ?RouteGroupAttribute
    {
        $attributes = $reflection->getAttributes(RouteGroupAttribute::class);

        if ($attributes === []) {
            return null;
        }

        // #[RouteGroup] is not repeatable, but PHP only enforces that when the
        // second attribute is instantiated — and we instantiate only the first.
        // Surface the misuse loudly at scan time rather than silently dropping it.
        if (count($attributes) > 1) {
            throw new \LogicException(sprintf(
                '%s declares %d #[RouteGroup] attributes; only one is allowed.',
                $reflection->getName(),
                count($attributes),
            ));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Join a group prefix to a method's route path. An empty prefix leaves the
     * path untouched; otherwise the two are joined and canonicalized so the
     * emitted path matches what Router::normalize() would store ("/" . trimmed):
     * a leading slash is guaranteed regardless of how the prefix was written,
     * and a group's root ("/") collapses to the bare prefix ("/admin").
     */
    private function prefix(string $prefix, string $path): string
    {
        if ($prefix === '') {
            return $path;
        }

        $joined = '/' . trim(rtrim($prefix, '/') . '/' . ltrim($path, '/'), '/');

        return $joined === '/' ? '/' : $joined;
    }
}
