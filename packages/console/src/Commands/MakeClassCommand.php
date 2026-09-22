<?php

declare(strict_types=1);

namespace Hydra\Console\Commands;

use Hydra\Console\Argument;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;

/**
 * Shared base for the class-emitting stub generators (make:controller,
 * make:ability).
 */
abstract class MakeClassCommand extends Command
{
    public function __construct(private readonly string $targetDir) {}

    public function arguments(): array
    {
        return [Argument::required('name', $this->nameHint())];
    }

    public function options(): array
    {
        return [Option::flag('force', 'f', 'Overwrite an existing file')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $class = $this->className($input->argument('name'));
        if ($class === '') {
            $output->error('Name must contain at least one letter or digit.');

            return ExitCode::Invalid;
        }

        $path = $this->targetDir . '/' . $class . '.php';

        if (is_file($path) && !$input->flag('force')) {
            $output->error("{$class} already exists. Re-run with --force to overwrite it.");

            return ExitCode::Failure;
        }

        if (!is_dir($this->targetDir) && !mkdir($this->targetDir, 0o775, true) && !is_dir($this->targetDir)) {
            $output->error("Could not create directory {$this->targetDir}.");

            return ExitCode::Failure;
        }

        file_put_contents($path, $this->stub($class));

        $output->success("Created {$class}");
        $this->afterCreate($output, $class);

        return ExitCode::Success;
    }

    /** The argument description shown in help, e.g. 'The controller name, e.g. "post"'. */
    abstract protected function nameHint(): string;

    /** A class-name suffix the generator guarantees, e.g. 'Controller'; '' for none. */
    abstract protected function suffix(): string;

    /** The full file contents for the given class name. */
    abstract protected function stub(string $class): string;

    /** Hook for a post-create reminder (e.g. "register this in CONTROLLERS"). */
    protected function afterCreate(OutputInterface $output, string $class): void {}

    /**
     * Normalise a loose name into a PascalCase class with the guaranteed suffix:
     * "blog post" / "blog-post" / "BlogPost" all become "BlogPost", and a
     * make:controller "post" becomes "PostController" (suffix added once).
     */
    private function className(string $name): string
    {
        $words = preg_replace('/[^A-Za-z0-9]+/', ' ', $name) ?? '';
        $class = str_replace(' ', '', ucwords(trim($words)));

        if ($class === '') {
            return '';
        }

        $suffix = $this->suffix();
        if ($suffix !== '' && !str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        return $class;
    }
}
