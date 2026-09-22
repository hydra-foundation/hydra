<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Http\Console\RouteCacheClearCommand;
use Hydra\Http\Console\RouteCacheCommand;
use Hydra\Http\RouteCache;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RouteCacheClearCommand::class)]
#[CoversClass(RouteCacheCommand::class)]
final class ShippedCommandsTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        $cache = new RouteCache(sys_get_temp_dir() . '/hydra-declared-routes.php', []);

        yield 'route:cache' => new RouteCacheCommand($cache, []);
        yield 'route:cache:clear' => new RouteCacheClearCommand($cache);
    }
}
