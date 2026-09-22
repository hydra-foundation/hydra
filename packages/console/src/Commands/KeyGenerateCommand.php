<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;

/**
 * Generates a 256-bit application key (64 hex chars) and writes it to APP_KEY
 * in the .env file.
 */
#[AsCommand(
    name: 'key:generate',
    description: 'Generate the application key and write it to .env',
)]
final class KeyGenerateCommand extends Command
{
    public function __construct(private readonly string $envPath) {}

    public function options(): array
    {
        return [
            Option::flag(
                'force',
                'f',
                'Overwrite an existing APP_KEY (invalidates anything sealed with the old key)',
            ),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        if (!is_file($this->envPath)) {
            $output->error("No .env file at {$this->envPath}. Copy .env.example to .env first.");

            return ExitCode::Failure;
        }

        $contents = file_get_contents($this->envPath);

        if ($this->currentKey($contents) !== '' && !$input->flag('force')) {
            $output->error('APP_KEY is already set. Re-run with --force to overwrite it.');

            return ExitCode::Failure;
        }

        $key = bin2hex(random_bytes(32));
        file_put_contents($this->envPath, $this->withKey($contents, $key));

        $output->success("Application key set: {$key}");

        return ExitCode::Success;
    }

    /** The current APP_KEY value, or '' when unset/empty. */
    private function currentKey(string $contents): string
    {
        return preg_match('/^APP_KEY=(.*)$/m', $contents, $m) === 1 ? trim($m[1]) : '';
    }

    /** Replace the APP_KEY line in place, or append one if the file has none. */
    private function withKey(string $contents, string $key): string
    {
        if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
            return preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$key}", $contents, 1);
        }

        return rtrim($contents, "\n") . "\nAPP_KEY={$key}\n";
    }
}
