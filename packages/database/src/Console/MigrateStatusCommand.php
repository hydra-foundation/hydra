<?php

declare(strict_types=1);

namespace Hydra\Database\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Database\MigrationRunner;

/**
 * Lists every migration on disk and whether it has been applied, a read-only
 * view of where the database stands relative to the migrations directory.
 */
#[AsCommand(
    name: 'migrate:status',
    description: 'Show which migrations have run and which are pending',
)]
final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $status = $this->runner->status();

        if ($status === []) {
            $output->warning('No migrations found.');
            return ExitCode::Success;
        }

        $output->table(
            ['Migration', 'Status'],
            array_map(
                static fn (array $row): array => [
                    $row['filename'],
                    $row['applied'] ? 'applied' : 'pending',
                ],
                $status,
            ),
        );

        return ExitCode::Success;
    }
}
