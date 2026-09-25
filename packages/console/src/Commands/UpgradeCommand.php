<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Closure;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;

/**
 * For a development checkout: the lock it leaves is what gets deployed. The
 * steps after Composer run as fresh processes, since this one still has the
 * old framework loaded.
 */
#[AsCommand(
    name: 'hydra:upgrade',
    description: 'Update the hydrakit packages within their constraints, migrate, and clear the route cache',
)]
final class UpgradeCommand extends Command
{
    /** @var Closure(list<string>, string): int */
    private readonly Closure $run;

    /** @param (Closure(list<string>, string): int)|null $run runs a command in a directory and returns its exit code */
    public function __construct(private readonly string $basePath, ?Closure $run = null)
    {
        $this->run = $run ?? self::passthrough(...);
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $before = $this->installed();

        if ($before === []) {
            $output->error("No hydrakit packages are installed under {$this->basePath}/vendor.");

            return ExitCode::Failure;
        }

        $linked = array_values(array_filter(array_keys($before), fn (string $name): bool => is_link("{$this->basePath}/vendor/{$name}")));

        if ($linked !== []) {
            // Updating from composer.json would swap the links for Packagist copies of the last tag.
            $output->error(
                "vendor/{$linked[0]} is a link to a local checkout, so it already holds whatever that checkout does. "
                . 'Pull there instead; COMPOSER=composer.dev.json composer update refreshes the links.',
            );

            return ExitCode::Failure;
        }

        if (($this->run)(['composer', 'update', 'hydrakit/*'], $this->basePath) !== 0) {
            $output->error('composer update failed; nothing was migrated.');

            return ExitCode::Failure;
        }

        $after = $this->installed();
        $changed = array_filter($after, static fn (string $v, string $name): bool => ($before[$name] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);

        if ($changed === []) {
            $output->success('Already on the newest release the constraints allow: ' . ($before['hydrakit/core'] ?? reset($before)) . '.');

            return ExitCode::Success;
        }

        $output->table(
            ['Package', 'Before', 'After'],
            array_map(static fn (string $name): array => [$name, $before[$name] ?? '—', $after[$name]], array_keys($changed)),
        );

        foreach (['migrate:run', 'route:cache:clear'] as $step) {
            if (($this->run)([PHP_BINARY, 'bin/console', $step], $this->basePath) !== 0) {
                $output->error("{$step} failed. The packages are updated; run it again once it is fixed.");

                return ExitCode::Failure;
            }
        }

        $output->success('Upgraded. Commit composer.lock and deploy it.');

        return ExitCode::Success;
    }

    /** @return array<string, string> version by package name */
    private function installed(): array
    {
        $file = $this->basePath . '/vendor/composer/installed.php';

        if (!is_file($file)) {
            return [];
        }

        // Read before and after Composer rewrites it; a cached compile would report the upgrade as a no-op.
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }

        $installed = (static fn (): mixed => require $file)();
        $versions = [];

        foreach (is_array($installed['versions'] ?? null) ? $installed['versions'] : [] as $name => $package) {
            if (str_starts_with((string) $name, 'hydrakit/') && is_array($package) && isset($package['pretty_version'])) {
                $versions[(string) $name] = (string) $package['pretty_version'];
            }
        }

        ksort($versions);

        return $versions;
    }

    /** @param list<string> $command */
    private static function passthrough(array $command, string $cwd): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $cwd);

        return is_resource($process) ? proc_close($process) : 1;
    }
}
