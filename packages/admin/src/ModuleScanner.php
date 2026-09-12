<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ScreenInterface;
use Hydra\Admin\Contracts\SubmittableInterface;

/**
 * Turns blueprints into the same plain route definitions RouteScanner emits for
 * controllers, so module screens are ordinary routes: listable and matchable
 * like any other.
 */
final class ModuleScanner
{
    /**
     * @param iterable<Blueprint> $blueprints
     * @param list<class-string> $middleware
     * @return list<array<string, mixed>>
     */
    public function scan(iterable $blueprints, string $prefix, array $middleware = []): array
    {
        $routes = [];

        foreach ($blueprints as $blueprint) {
            foreach ($this->ordered($blueprint->screens) as $screen) {
                $path = $this->path($prefix, $blueprint->slug, $screen->path());
                $name = $blueprint->slug . '.' . $screen->name();

                $routes[] = [
                    'method' => $screen->method(),
                    'path' => $path,
                    'handler' => $screen->handler(),
                    'middleware' => $middleware,
                    'name' => $name,
                ];

                $submit = $screen instanceof SubmittableInterface ? $screen->submitHandler() : null;

                if ($submit !== null) {
                    $routes[] = [
                        'method' => 'POST',
                        'path' => $path,
                        'handler' => $submit,
                        'middleware' => $middleware,
                        'name' => $name . '.submit',
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * Literal paths first. The router takes the first route whose path matches,
     * so a screen at "new" has to be registered ahead of one at "{id}" or it is
     * never reached, and that must not depend on the order a module happened to
     * declare its screens in. Ties keep their declared order.
     *
     * @param list<ScreenInterface> $screens
     * @return list<ScreenInterface>
     */
    private function ordered(array $screens): array
    {
        usort($screens, static fn (ScreenInterface $a, ScreenInterface $b): int
            => substr_count($a->path(), '{') <=> substr_count($b->path(), '{'));

        return $screens;
    }

    private function path(string $prefix, string $slug, string $path): string
    {
        return '/' . trim(rtrim($prefix, '/') . '/' . $slug . '/' . ltrim($path, '/'), '/');
    }
}
