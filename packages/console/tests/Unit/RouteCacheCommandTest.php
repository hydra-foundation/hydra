<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Commands\RouteCacheCommand;
use Hydra\Console\Commands\RouteCacheClearCommand;
use Hydra\Http\Attributes\Route;
use Hydra\Http\RouteCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** A controller the scanner can reflect: never instantiated, only its attributes read. */
final class CacheableRoutesController
{
    #[Route('/posts')]
    public function index(): void {}

    #[Route('/posts', methods: ['POST'])]
    public function store(): void {}
}

#[CoversClass(RouteCacheCommand::class)]
final class RouteCacheCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/hydra-routecachecmd-' . uniqid('', true) . '/routes.php';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

    public function test_compiles_the_controller_routes_to_the_cache(): void
    {
        $cache = new RouteCache($this->path, [CacheableRoutesController::class]);
        $tester = new CommandTester(
            new RouteCacheCommand($cache, [CacheableRoutesController::class]),
        );

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Cached 2 route(s)', $tester->getDisplay());

        // The artifact is loadable and holds exactly the scanned routes.
        $routes = $cache->load();
        $this->assertCount(2, $routes);
        $this->assertSame('/posts', $routes[0]['path']);
    }

    public function test_clear_deletes_the_cache(): void
    {
        $cache = new RouteCache($this->path, [CacheableRoutesController::class]);
        $cache->store([['method' => 'GET', 'path' => '/x', 'handler' => ['X', 'y'], 'middleware' => []]]);

        $tester = new CommandTester(new RouteCacheClearCommand($cache));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('cleared', $tester->getDisplay());
        $this->assertNull($cache->load());
    }

    public function test_clear_is_a_successful_no_op_when_cache_is_cold(): void
    {
        $tester = new CommandTester(new RouteCacheClearCommand(new RouteCache($this->path, [])));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('already clear', $tester->getDisplay());
    }
}
