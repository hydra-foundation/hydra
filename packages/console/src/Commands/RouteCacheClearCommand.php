<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Http\RouteCache;

/**
 * Deletes the route cache file. After this the web path falls back to scanning
 * on every request until route:cache is run again. Clearing an already-cold
 * cache is success, not an error.
 */
#[AsCommand(
    name: 'route:cache:clear',
    description: 'Delete the compiled route cache',
)]
final class RouteCacheClearCommand extends Command
{
    public function __construct(private readonly RouteCache $cache) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $output->success($this->cache->clear()
            ? 'Route cache cleared.'
            : 'Route cache was already clear.');

        return ExitCode::Success;
    }
}
