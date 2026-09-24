<?php

declare(strict_types=1);

namespace Hydra\Http\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\Http\HealthMiddleware;
use Hydra\Http\Maintenance;

/**
 * Takes the application down: every request gets a 503 with the message, and a
 * Retry-After when one is given. Running it again replaces both. The health
 * endpoint keeps answering, and the scheduler keeps running.
 */
#[AsCommand(
    name: 'down',
    description: 'Put the application into maintenance mode',
)]
final class DownCommand extends Command
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function options(): array
    {
        return [
            Option::value('message', null, 'What visitors are told', Maintenance::DEFAULT_MESSAGE),
            Option::value('retry', null, 'Seconds until a client should try again, sent as Retry-After'),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $message = trim($input->option('message', Maintenance::DEFAULT_MESSAGE));
        $retry = $input->option('retry');

        if ($retry !== '' && (!ctype_digit($retry) || (int) $retry < 1)) {
            $output->error("--retry takes a whole number of seconds, at least 1; got \"{$retry}\".");

            return ExitCode::Failure;
        }

        $this->maintenance->down(
            $message === '' ? Maintenance::DEFAULT_MESSAGE : $message,
            $retry === '' ? null : (int) $retry,
        );

        $output->success('The application is down for maintenance. `up` brings it back.');
        $output->note(sprintf('%s still answers, and scheduled work still runs.', HealthMiddleware::PATH));

        return ExitCode::Success;
    }
}
