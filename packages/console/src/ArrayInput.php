<?php

declare(strict_types=1);

namespace Hydra\Console;

use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Contracts\InputInterface;

/**
 * Arguments and options given directly, rather than parsed from a command line.
 *
 * Two callers want this. A test states what the command line came out as; and a
 * command that composes others -- make:admin runs five generators in turn --
 * has to hand each one its arguments without a terminal in the middle.
 *
 * No parsing, deliberately. Turning `-fw --table=posts` into values is the
 * console library's job, and a second implementation of it here would be one
 * more thing to disagree with the real one.
 */
final class ArrayInput implements InputInterface
{
    /**
     * @param array<string, string> $arguments
     * @param array<string, string> $options values given as --name=value
     * @param list<string> $flags flags present on the command line
     */
    public function __construct(
        private readonly array $arguments = [],
        private readonly array $options = [],
        private readonly array $flags = [],
    ) {}

    /**
     * The same input, with the command's declared defaults filled in.
     *
     * Symfony's parser applies a declared default before a command ever reads
     * the option, so a command written against a real terminal may never pass
     * a fallback to option(). Built without this, the same command composed by
     * another -- or driven by a test -- reads '' where it read the default,
     * and the two implementations of this contract quietly disagree.
     *
     * @param array<string, string> $arguments
     * @param array<string, string> $options
     * @param list<string> $flags
     */
    public static function forCommand(
        CommandInterface $command,
        array $arguments = [],
        array $options = [],
        array $flags = [],
    ): self {
        foreach ($command->options() as $option) {
            if ($option->takesValue && $option->default !== null && !array_key_exists($option->name, $options)) {
                $options[$option->name] = $option->default;
            }
        }

        foreach ($command->arguments() as $argument) {
            if ($argument->default !== null && !array_key_exists($argument->name, $arguments)) {
                $arguments[$argument->name] = $argument->default;
            }
        }

        return new self($arguments, $options, $flags);
    }

    /** @param list<string> $flags */
    public static function withFlags(array $flags): self
    {
        return new self(flags: $flags);
    }

    /** @param array<string, string> $arguments */
    public static function withArguments(array $arguments): self
    {
        return new self(arguments: $arguments);
    }

    public function hasArgument(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function argument(string $name, string $default = ''): string
    {
        return $this->arguments[$name] ?? $default;
    }

    public function option(string $name, string $default = ''): string
    {
        return $this->options[$name] ?? $default;
    }

    public function flag(string $name): bool
    {
        return in_array($name, $this->flags, true);
    }
}
