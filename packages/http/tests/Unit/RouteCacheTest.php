<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\RouteCache;
use PHPUnit\Framework\TestCase;

final class RouteCacheTest extends TestCase
{
    /** The controllers list the sample routes were "scanned" from. */
    private const CONTROLLERS = [
        'App\\Controllers\\BlogController',
        'App\\Controllers\\AdminController',
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-routecache-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        // The cache may live a level down (the missing-directory test), so clean
        // recursively rather than assuming a flat directory.
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    private function cache(string $path, array $controllers = self::CONTROLLERS): RouteCache
    {
        return new RouteCache($path, $controllers);
    }

    /** A representative scan result: plain arrays, class-strings, empty and populated middleware. */
    private function sampleRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/posts',
                'handler' => ['App\\Controllers\\BlogController', 'index'],
                'middleware' => [],
            ],
            [
                'method' => 'POST',
                'path' => '/admin/users',
                'handler' => ['App\\Controllers\\AdminController', 'store'],
                'middleware' => ['App\\Middleware\\Authenticate', 'App\\Middleware\\RequireAdmin'],
            ],
        ];
    }

    public function testLoadReturnsNullWhenNoCacheFileExists(): void
    {
        $this->assertNull($this->cache($this->dir . '/routes.php')->load());
    }

    public function testStoreThenLoadRoundTripsTheRoutesExactly(): void
    {
        $cache = $this->cache($this->dir . '/routes.php');
        $routes = $this->sampleRoutes();

        $cache->store($routes);

        // Same values AND same order/keys — the Router iterates in order, so a
        // reordered cache would silently change matching precedence.
        $this->assertSame($routes, $cache->load());
    }

    public function testStoreCreatesMissingParentDirectories(): void
    {
        $path = $this->dir . '/bootstrap/cache/routes.php';
        $cache = $this->cache($path);

        $cache->store($this->sampleRoutes());

        $this->assertFileExists($path);
        $this->assertSame($this->sampleRoutes(), $cache->load());
    }

    public function testStoredFileIsPlainPhpReturningFingerprintAndRoutes(): void
    {
        $path = $this->dir . '/routes.php';
        $this->cache($path)->store($this->sampleRoutes());

        // The artifact is a require-able PHP file (opcache-friendly), not a
        // blob: a wrapper array of the controllers fingerprint plus the routes.
        $this->assertStringStartsWith('<?php', file_get_contents($path));

        $artifact = require $path;
        $this->assertSame(['controllers', 'routes'], array_keys($artifact));
        $this->assertSame($this->sampleRoutes(), $artifact['routes']);
    }

    public function testLoadReturnsNullWhenControllersListChanged(): void
    {
        $path = $this->dir . '/routes.php';
        $this->cache($path)->store($this->sampleRoutes());

        // A controller was added since route:cache ran — the artifact is stale
        // and must read as a miss so the caller re-scans, not as routes that
        // silently lack the new controller.
        $grown = [...self::CONTROLLERS, 'App\\Controllers\\NewController'];
        $this->assertNull($this->cache($path, $grown)->load());
    }

    public function testLoadReturnsNullWhenControllersListReordered(): void
    {
        $path = $this->dir . '/routes.php';
        $this->cache($path)->store($this->sampleRoutes());

        // Order matters: scan output order sets matching precedence, so a
        // reordered list is a different cache, not the same one.
        $this->assertNull($this->cache($path, array_reverse(self::CONTROLLERS))->load());
    }

    public function testLoadTreatsPreFingerprintArtifactAsStale(): void
    {
        // A cache file written by the old format (a bare routes list, no
        // fingerprint wrapper) reads as a miss — stale, not broken.
        $path = $this->dir . '/routes.php';
        mkdir($this->dir, 0775, true);
        file_put_contents(
            $path,
            '<?php' . PHP_EOL . 'return ' . var_export($this->sampleRoutes(), true) . ';' . PHP_EOL,
        );

        $this->assertNull($this->cache($path)->load());
    }

    public function testClearRemovesTheCacheAndReportsIt(): void
    {
        $path = $this->dir . '/routes.php';
        $cache = $this->cache($path);
        $cache->store($this->sampleRoutes());

        $this->assertTrue($cache->clear());
        $this->assertFileDoesNotExist($path);
        $this->assertNull($cache->load());
    }

    public function testClearOnAColdCacheIsANoOp(): void
    {
        // Clearing an absent cache is success, not an error — it just had
        // nothing to remove.
        $this->assertFalse($this->cache($this->dir . '/routes.php')->clear());
    }

    public function testStoreOverwritesAnExistingCache(): void
    {
        $cache = $this->cache($this->dir . '/routes.php');
        $cache->store($this->sampleRoutes());

        $rebuilt = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => ['App\\Controllers\\HomeController', 'health'],
            'middleware' => [],
        ]];
        $cache->store($rebuilt);

        // Rebuilding (delete + repopulate is the deploy story; here just a
        // second store) fully replaces the previous artifact.
        $this->assertSame($rebuilt, $cache->load());
    }
}
