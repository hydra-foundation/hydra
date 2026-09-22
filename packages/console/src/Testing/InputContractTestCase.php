<?php

declare(strict_types=1);

namespace Hydra\Console\Testing;

use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use PHPUnit\Framework\TestCase;

/**
 * What every input owes a command, published so both implementations answer
 * the same way.
 *
 * This exists because they did not. A command line parsed by the console
 * library arrives with the declared defaults already filled in, so a command
 * reading an option it declared a default for never passes a fallback. Built
 * by hand instead — by a test, or by a command composing another — the same
 * read came back empty, and a generator that had always defaulted to the
 * `user` role started refusing every role as unknown. Nothing in the framework
 * noticed; an application's test did.
 */
abstract class InputContractTestCase extends TestCase
{
    /**
     * An input for $command, as though these had been typed.
     *
     * @param array<string, string> $arguments
     * @param array<string, string> $options values given as --name=value
     * @param list<string> $flags flags present on the command line
     */
    abstract protected function input(
        CommandInterface $command,
        array $arguments = [],
        array $options = [],
        array $flags = [],
    ): InputInterface;

    private function command(): CommandInterface
    {
        return new DeclaringCommand;
    }

    /**
     * An input with the required argument supplied, which every case below
     * wants. Whether a missing required argument is refused belongs to whatever
     * parses the command line — this contract is about reading what it produced.
     *
     * @param array<string, string> $arguments
     * @param array<string, string> $options
     * @param list<string> $flags
     */
    private function given(array $arguments = [], array $options = [], array $flags = []): InputInterface
    {
        return $this->input($this->command(), ['name' => 'ada', ...$arguments], $options, $flags);
    }

    public function test_an_argument_that_was_typed_is_read_back(): void
    {
        $input = $this->given();

        $this->assertTrue($input->hasArgument('name'));
        $this->assertSame('ada', $input->argument('name'));
    }

    public function test_an_optional_argument_that_was_not_typed_is_absent(): void
    {
        // 'note' is optional and declares no default, so it is genuinely absent.
        $input = $this->given();

        $this->assertFalse($input->hasArgument('note'));
        $this->assertSame('fallback', $input->argument('note', 'fallback'));
    }

    public function test_an_argument_declared_with_a_default_reads_as_that_default(): void
    {
        $this->assertSame('round', $this->given()->argument('shape'));
    }

    public function test_an_undeclared_argument_reads_as_the_callers_default(): void
    {
        $input = $this->given();

        $this->assertFalse($input->hasArgument('nope'));
        $this->assertSame('fallback', $input->argument('nope', 'fallback'));
    }

    public function test_an_option_that_was_given_is_read_back(): void
    {
        $this->assertSame('posts', $this->given(options: ['table' => 'posts'])->option('table'));
    }

    public function test_an_option_declared_with_a_default_reads_as_that_default(): void
    {
        // The one this case was written for.
        $this->assertSame('guessed', $this->given()->option('table'));
    }

    public function test_an_option_with_no_declared_default_reads_as_the_callers(): void
    {
        $this->assertSame('none', $this->given()->option('columns', 'none'));
    }

    public function test_a_flag_is_true_when_given(): void
    {
        $this->assertTrue($this->given(flags: ['force'])->flag('force'));
    }

    public function test_a_flag_is_false_when_not_given(): void
    {
        $this->assertFalse($this->given()->flag('force'));
    }

    public function test_an_undeclared_flag_is_false_rather_than_an_error(): void
    {
        // Asking whether the user typed something is always answerable, and the
        // answer for a name nothing declares is no.
        $this->assertFalse($this->given()->flag('nope'));
    }

    public function test_one_flag_given_does_not_set_another(): void
    {
        $input = $this->given(flags: ['force']);

        $this->assertTrue($input->flag('force'));
        $this->assertFalse($input->flag('writable'));
    }
}

/** Declares one of everything the contract distinguishes between. */
#[AsCommand(name: 'contract:declaring', description: 'Declares one of everything')]
final class DeclaringCommand extends Command
{
    public function arguments(): array
    {
        return [
            Argument::required('name', 'Who'),
            Argument::optional('shape', 'The shape', 'round'),
            Argument::optional('note', 'An aside, with no default'),
        ];
    }

    public function options(): array
    {
        return [
            Option::flag('force', 'f', 'Overwrite'),
            Option::flag('writable', 'w', 'Also write the write side'),
            Option::value('table', 't', 'The table', 'guessed'),
            Option::value('columns', 'c', 'A column list'),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}
