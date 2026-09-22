<?php

declare(strict_types=1);

namespace Hydra\Console\Testing;

use Hydra\Console\Argument;
use Hydra\Console\CommandScanner;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Option;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every command declares about itself, published because commands ship
 * from more packages than this one: database owns migrate:*, http owns
 * route:cache, admin owns admin:check, and an application owns the rest.
 *
 * These exist because of a gap the fakes open up. A test drives a command with
 * {@see \Hydra\Console\ArrayInput}, which answers whatever the test puts in it,
 * so a command that reads `--force` while declaring `--overwrite` still passes
 * its own unit test and still fails at a real terminal. Nothing else holds a
 * command's declaration against the names it reads.
 */
abstract class CommandContractTestCase extends TestCase
{
    /**
     * Every command under test, keyed by the name it must declare.
     *
     * Static because a data provider is: build each command with whatever it
     * needs to be constructed, since none of them is executed here.
     *
     * @return iterable<string, CommandInterface>
     */
    abstract public static function commands(): iterable;

    /** @return iterable<string, array{CommandInterface, string}> */
    final public static function declaredCommands(): iterable
    {
        foreach (static::commands() as $name => $command) {
            yield $name => [$command, $name];
        }
    }

    #[DataProvider('declaredCommands')]
    public function test_it_is_named_and_described(CommandInterface $command, string $name): void
    {
        $described = (new CommandScanner)->describe($command);

        $this->assertSame($name, $described->name);
        $this->assertNotSame('', $described->description, 'A command with no description is invisible in `list`.');
    }

    #[DataProvider('declaredCommands')]
    public function test_its_declarations_are_well_formed(CommandInterface $command, string $name): void
    {
        $names = [];

        foreach ($command->arguments() as $argument) {
            $this->assertInstanceOf(Argument::class, $argument);
            $this->assertNotSame('', $argument->name);
            $names[] = 'argument:' . $argument->name;
        }

        foreach ($command->options() as $option) {
            $this->assertInstanceOf(Option::class, $option);
            $this->assertNotSame('', $option->name);
            $names[] = 'option:' . $option->name;
        }

        $this->assertSame($names, array_unique($names), "{$name} declares a name twice.");
    }

    #[DataProvider('declaredCommands')]
    public function test_an_optional_argument_never_precedes_a_required_one(CommandInterface $command, string $name): void
    {
        // Positional: once one may be omitted, everything after it is ambiguous.
        // Stated as a sort so a command declaring no arguments still asserts,
        // rather than passing vacuously under failOnRisky.
        $required = array_map(
            static fn (Argument $argument): bool => $argument->required,
            $command->arguments(),
        );

        $inOrder = $required;
        rsort($inOrder);

        $this->assertSame($inOrder, $required, "{$name} declares a required argument after an optional one.");
    }

    /**
     * For the command that branches on a flag: the specific mismatch the fakes
     * cannot catch, since ArrayInput answers a flag nobody declared.
     */
    final protected function assertDeclaresOption(CommandInterface $command, string $option): void
    {
        $declared = array_map(static fn (Option $o): string => $o->name, $command->options());

        $this->assertContains($option, $declared, $command::class . " reads --{$option} without declaring it.");
    }
}
