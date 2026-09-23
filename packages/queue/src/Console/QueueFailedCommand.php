<?php

declare(strict_types=1);

namespace Hydra\Queue\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\FailedJob;

#[AsCommand(
    name: 'queue:failed',
    description: 'List the jobs that ran out of tries',
)]
final class QueueFailedCommand extends Command
{
    private const REASON_LENGTH = 120;

    public function __construct(private readonly DatabaseQueue $queue) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $failed = $this->queue->failed();

        if ($failed === []) {
            $output->note('No failed jobs.');

            return ExitCode::Success;
        }

        $output->table(['ID', 'Job', 'Failed (UTC)', 'Reason'], array_map($this->row(...), $failed));
        $output->note('queue:retry <id> puts one back on the queue; queue:retry --all puts back every one.');

        return ExitCode::Success;
    }

    /** @return list<string> */
    private function row(FailedJob $job): array
    {
        $reason = $job->reason();

        if (mb_strlen($reason) > self::REASON_LENGTH) {
            $reason = mb_substr($reason, 0, self::REASON_LENGTH - 1) . '…';
        }

        return [(string) $job->id, $job->job, gmdate('Y-m-d H:i:s', $job->failedAt), $reason];
    }
}
