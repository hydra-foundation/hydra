<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * Reads and writes the compiled route cache: the plain array produced by
 * RouteScanner::scan(), written as a PHP file that `return`s it alongside a
 * fingerprint of the controllers list it was compiled from.
 *
 * @phpstan-import-type RouteDefinition from RouteScanner
 */
final class RouteCache
{
    /** @param list<class-string> $controllers */
    public function __construct(
        private readonly string $path,
        private readonly array $controllers,
    ) {}

    /** @return list<RouteDefinition>|null */
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $cached = require $this->path;

        if (
            !is_array($cached)
            || !isset($cached['controllers'], $cached['routes'])
            || $cached['controllers'] !== $this->fingerprint()
        ) {
            return null;
        }

        return $cached['routes'];
    }

    /**
     * Compile the route definitions to the cache file, fingerprinted with the
     * controllers list they were scanned from
     *
     * @param list<RouteDefinition> $routes
     */
    public function store(array $routes): void
    {
        $dir = dirname($this->path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $artifact = [
            'controllers' => $this->fingerprint(),
            'routes' => $routes,
        ];

        $contents = '<?php' . PHP_EOL . PHP_EOL
            . 'return ' . var_export($artifact, true) . ';' . PHP_EOL;

        $tmp = tempnam($dir, 'routes-');
        file_put_contents($tmp, $contents);
        rename($tmp, $this->path);

        // Drop any previously-compiled version opcache may be holding for this
        // path so a rebuild (delete + repopulate) takes effect on the next
        // request rather than waiting for opcache to notice the new mtime.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    /**
     * Remove the cache file if it exists, returning whether anything was deleted
     */
    public function clear(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        unlink($this->path);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }

        return true;
    }

    /**
     * The controllers-list fingerprint embedded in the artifact. Order-sensitive
     * on purpose: reordering the list reorders the scan output, which changes
     * route matching precedence — a different list is a different cache.
     */
    private function fingerprint(): string
    {
        return sha1(implode("\n", $this->controllers));
    }
}
