<?php

declare(strict_types=1);

namespace Hydra\Queue\Console;

use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Queue\DatabaseQueue;

#[AsCommand(
    name: 'queue:forget',
    description: 'Delete a failed job without running it again',
)]
final class QueueForgetCommand extends Command
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function arguments(): array
    {
        return [Argument::required('id', 'The failed job to delete, as queue:failed lists it')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $id = $input->argument('id');

        if (!ctype_digit($id) || !$this->queue->forget((int) $id)) {
            $output->error("No failed job has the id \"{$id}\". queue:failed lists them.");

            return ExitCode::Failure;
        }

        $output->success("Failed job {$id} is deleted.");

        return ExitCode::Success;
    }
}
