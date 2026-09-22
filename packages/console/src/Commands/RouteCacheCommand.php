<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Http\RouteCache;
use Hydra\Http\RouteScanner;

/**
 * Compiles the controller #[Route] attributes to the route cache file. This is
 * the sole writer of the cache; the web path only ever reads it (see
 * AppServiceProvider::compileRoutes). Run it at deploy time when ROUTE_CACHE is
 * on; re-run (or route:cache:clear) after changing any route.
 */
#[AsCommand(
    name: 'route:cache',
    description: 'Compile the controller routes to the route cache',
)]
final class RouteCacheCommand extends Command
{
    /**
     * @param list<class-string> $controllers
     */
    public function __construct(
        private readonly RouteCache $cache,
        private readonly array $controllers,
    ) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $routes = (new RouteScanner)->scan($this->controllers);
        $this->cache->store($routes);

        $output->success(sprintf('Cached %d route(s).', count($routes)));

        return ExitCode::Success;
    }
}
