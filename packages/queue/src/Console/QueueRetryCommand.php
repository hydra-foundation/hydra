<?php

declare(strict_types=1);

namespace Hydra\Queue\Console;

use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\FailedJob;

#[AsCommand(
    name: 'queue:retry',
    description: 'Put a failed job back on the queue with its tries restored',
)]
final class QueueRetryCommand extends Command
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function arguments(): array
    {
        return [Argument::optional('id', 'The failed job to retry, as queue:failed lists it')];
    }

    public function options(): array
    {
        return [Option::flag('all', null, 'Retry every failed job')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $id = $input->argument('id');
        $all = $input->flag('all');

        if (($id === '') === !$all) {
            $output->error('Name one failed job by its id, or pass --all.');

            return ExitCode::Failure;
        }

        return $all ? $this->retryAll($output) : $this->retryOne($id, $output);
    }

    private function retryOne(string $id, OutputInterface $output): ExitCode
    {
        if (!ctype_digit($id) || !$this->queue->retry((int) $id)) {
            $output->error("No failed job has the id \"{$id}\". queue:failed lists them.");

            return ExitCode::Failure;
        }

        $output->success("Job {$id} is back on the queue.");

        return ExitCode::Success;
    }

    private function retryAll(OutputInterface $output): ExitCode
    {
        $retried = count(array_filter(
            $this->queue->failed(),
            fn (FailedJob $job): bool => $this->queue->retry($job->id),
        ));

        if ($retried === 0) {
            $output->note('No failed jobs.');

            return ExitCode::Success;
        }

        $output->success($retried === 1 ? '1 job is back on the queue.' : "{$retried} jobs are back on the queue.");

        return ExitCode::Success;
    }
}
