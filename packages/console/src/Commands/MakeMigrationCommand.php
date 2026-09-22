<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Argument;

/**
 * Scaffolds an empty migration file: {Ymd_His}_{slug}.sql with a header comment.
 */
#[AsCommand(
    name: 'make:migration',
    description: 'Create a new, empty timestamped .sql migration',
)]
final class MakeMigrationCommand extends Command
{
    public function __construct(private readonly string $migrationsPath) {}

    public function arguments(): array
    {
        return [Argument::required('name', 'A short description, e.g. "create posts table"')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $name = $input->argument('name');
        $slug = $this->slug($name);

        if ($slug === '') {
            $output->error('Migration name must contain at least one letter or digit.');
            return ExitCode::Failure;
        }

        if (!is_dir($this->migrationsPath) && !mkdir($this->migrationsPath, 0o775, true) && !is_dir($this->migrationsPath)) {
            $output->error("Could not create migrations directory at {$this->migrationsPath}.");
            return ExitCode::Failure;
        }

        $filename = date('Ymd_His') . '_' . $slug . '.sql';
        $path = $this->migrationsPath . '/' . $filename;

        file_put_contents($path, $this->template($name));

        $output->success("Created {$filename}");

        return ExitCode::Success;
    }

    /** Lowercase, non-alphanumerics collapsed to single underscores. */
    private function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';

        return trim($slug, '_');
    }

    private function template(string $name): string
    {
        return "-- Migration: {$name}\n"
            . '-- Created: ' . date('Y-m-d H:i:s') . "\n"
            . "-- Forward-only. One logical change per migration — MariaDB has no transactional DDL.\n\n";
    }
}
