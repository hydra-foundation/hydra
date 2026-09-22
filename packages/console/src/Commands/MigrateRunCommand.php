<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Database\MigrationRunner;

/**
 * Applies every pending .sql migration in order. Forward-only: migrations
 * already recorded in the `migrations` table are skipped, so re-running is safe.
 */
#[AsCommand(
    name: 'migrate:run',
    description: 'Apply all pending migrations',
)]
final class MigrateRunCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $applied = $this->runner->run();

        if ($applied === []) {
            $output->success('Nothing to migrate — already up to date.');
            return ExitCode::Success;
        }

        foreach ($applied as $filename) {
            $output->write("  ✓ {$filename}");
        }
        $output->success(sprintf('Applied %d migration(s).', count($applied)));

        return ExitCode::Success;
    }
}
