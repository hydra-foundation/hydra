<?php

declare(strict_types=1);

namespace Hydra\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Contracts\CommandInterface;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Reads {@see AsCommand} off a command class, the way {@see \Hydra\Http\RouteScanner}
 * reads {@see \Hydra\Http\Attributes\Route} off a controller.
 *
 * The point of doing it from the class string is that a name can be known
 * before the command is built. A console that had to construct `migrate:fresh`
 * to discover it is called `migrate:fresh` would open a database connection to
 * print a help listing on a machine with no database running.
 */
final class CommandScanner
{
    /**
     * @param class-string<CommandInterface>|CommandInterface $command
     * @throws InvalidArgumentException when the class carries no attribute
     */
    public function describe(string|CommandInterface $command): AsCommand
    {
        $class = is_string($command) ? $command : $command::class;

        $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);

        if ($attributes === []) {
            throw new InvalidArgumentException(sprintf(
                '%s must carry #[%s] to be registered as a command.',
                $class,
                AsCommand::class,
            ));
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Command names mapped to the classes answering them.
     *
     * @param iterable<class-string<CommandInterface>> $classes
     * @return array<string, class-string<CommandInterface>>
     * @throws InvalidArgumentException when two classes claim one name
     */
    public function scan(iterable $classes): array
    {
        $names = [];

        foreach ($classes as $class) {
            $name = $this->describe($class)->name;

            // Two commands under one name is a registration mistake that would
            // otherwise resolve to whichever was added last, silently.
            if (isset($names[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Both %s and %s are named "%s".',
                    $names[$name],
                    $class,
                    $name,
                ));
            }

            $names[$name] = $class;
        }

        return $names;
    }
}
