<?php

declare(strict_types=1);

namespace Hydra\Http\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Http\Maintenance;

/**
 * Brings the application back from maintenance. Already up is success.
 */
#[AsCommand(
    name: 'up',
    description: 'Bring the application out of maintenance mode',
)]
final class UpCommand extends Command
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $output->success($this->maintenance->up()
            ? 'The application is up.'
            : 'The application was already up.');

        return ExitCode::Success;
    }
}
