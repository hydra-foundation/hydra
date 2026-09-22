<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\Database\MigrationRunner;

/**
 * Drops every table and re-applies all migrations from scratch, a clean slate
 * for development.
 */
#[AsCommand(
    name: 'migrate:fresh',
    description: 'Drop all tables and re-run every migration',
)]
final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly bool $debug,
    ) {}

    public function options(): array
    {
        return [
            Option::flag('force', 'f', 'Run even when APP_DEBUG is off, and skip the confirmation prompt'),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $force = $input->flag('force');

        if (!$this->debug && !$force) {
            $output->error('APP_DEBUG is off — refusing to drop all tables. Re-run with --force if you really mean it.');

            return ExitCode::Failure;
        }

        // Default false: an output with nobody to ask answers the default, and
        // the safe answer to "drop every table" is no.
        if (!$force && !$output->confirm('This will DROP every table and re-run all migrations. Continue?', false)) {
            $output->warning('Aborted.');

            return ExitCode::Failure;
        }

        $applied = $this->runner->fresh();
        $output->success(sprintf('Database reset — applied %d migration(s).', count($applied)));

        return ExitCode::Success;
    }
}
