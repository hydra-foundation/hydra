<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\Core\Environment;

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

    /**
     * The current APP_KEY value as the application would read it, or '' when
     * unset or empty. The loader's own rule, so `APP_KEY=   # comment`, the
     * line .env.example ships, is empty here too rather than a key.
     */
    private function currentKey(string $contents): string
    {
        return preg_match('/^APP_KEY=(.*)$/m', $contents, $m) === 1 ? Environment::parseValue(trim($m[1])) : '';
    }

    /**
     * Replace the APP_KEY line in place, keeping its trailing comment, or
     * append one if the file has none.
     */
    private function withKey(string $contents, string $key): string
    {
        if (preg_match('/^APP_KEY=(.*)$/m', $contents, $m) !== 1) {
            return rtrim($contents, "\n") . "\nAPP_KEY={$key}\n";
        }

        $raw = rtrim($m[1]);
        // A line that is only a comment keeps all of it, its alignment
        // included; a value keeps only what the loader would strip as one (`#`
        // after whitespace). Either way the comment needs whitespace in front,
        // or behind the new key `#bare` would read as part of it.
        $comment = Environment::parseValue(trim($raw)) === ''
            ? (str_contains($raw, '#') ? $raw : '')
            : (preg_match('/\s+#.*$/', $raw, $c) === 1 ? $c[0] : '');

        if ($comment !== '' && !ctype_space($comment[0])) {
            $comment = " {$comment}";
        }

        return preg_replace_callback('/^APP_KEY=.*$/m', static fn (): string => "APP_KEY={$key}{$comment}", $contents, 1);
    }
}
