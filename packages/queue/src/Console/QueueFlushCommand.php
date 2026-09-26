<?php

declare(strict_types=1);

namespace Hydra\Queue\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Queue\DatabaseQueue;

#[AsCommand(
    name: 'queue:flush',
    description: 'Delete every failed job',
)]
final class QueueFlushCommand extends Command
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $deleted = $this->queue->flush();

        if ($deleted === 0) {
            $output->note('No failed jobs.');

            return ExitCode::Success;
        }

        $output->success($deleted === 1 ? '1 failed job deleted.' : "{$deleted} failed jobs deleted.");

        return ExitCode::Success;
    }
}
