<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\TaskRun;

/**
 * The one cron entry: `* * * * * php bin/console schedule:run`. Fails when a
 * task failed, so whatever watches cron's exit status hears about it.
 */
#[AsCommand(
    name: 'schedule:run',
    description: 'Run the scheduled tasks that are due this minute',
)]
final class ScheduleRunCommand extends Command
{
    public function __construct(private readonly Runner $runner) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $runs = $this->runner->run();

        if ($runs === []) {
            $output->note('Nothing is due.');

            return ExitCode::Success;
        }

        $output->table(['Task', 'Outcome', 'Detail'], array_map($this->row(...), $runs));

        foreach ($runs as $run) {
            if ($run->outcome === Outcome::Failed) {
                return ExitCode::Failure;
            }
        }

        return ExitCode::Success;
    }

    /** @return list<string> */
    private function row(TaskRun $run): array
    {
        $detail = match (true) {
            $run->items !== null => "{$run->items} items",
            $run->outcome === Outcome::Held && $run->heldMinutes !== null => "running for {$run->heldMinutes} min",
            $run->outcome === Outcome::Failed => 'see the log',
            default => '',
        };

        return [$run->class, $run->outcome->value, $detail];
    }
}
