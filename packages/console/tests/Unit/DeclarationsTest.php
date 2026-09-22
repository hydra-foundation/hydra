<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declarations a command is written in: what it accepts, and what it is
 * called. Small types, but they are the vocabulary every command uses and the
 * thing the adapter translates, so the defaults are worth pinning.
 */
#[CoversClass(Argument::class)]
#[CoversClass(AsCommand::class)]
#[CoversClass(Command::class)]
#[CoversClass(ExitCode::class)]
#[CoversClass(Option::class)]
final class DeclarationsTest extends TestCase
{
    public function test_a_required_argument_is_required_and_has_no_default(): void
    {
        $argument = Argument::required('name', 'Who');

        $this->assertSame('name', $argument->name);
        $this->assertSame('Who', $argument->description);
        $this->assertTrue($argument->required);
        $this->assertNull($argument->default);
    }

    public function test_an_optional_argument_carries_its_default(): void
    {
        $argument = Argument::optional('shape', 'The shape', 'round');

        $this->assertFalse($argument->required);
        $this->assertSame('round', $argument->default);
    }

    public function test_a_flag_takes_no_value(): void
    {
        $option = Option::flag('force', 'f', 'Overwrite');

        $this->assertSame('force', $option->name);
        $this->assertSame('f', $option->shortcut);
        $this->assertFalse($option->takesValue);
        $this->assertNull($option->default);
    }

    public function test_a_value_option_takes_one(): void
    {
        $option = Option::value('table', 't', 'The table', 'guessed');

        $this->assertTrue($option->takesValue);
        $this->assertSame('guessed', $option->default);
    }

    public function test_an_option_needs_no_shortcut(): void
    {
        $this->assertNull(Option::value('columns')->shortcut);
    }

    public function test_a_command_declares_nothing_by_default(): void
    {
        // Most commands take neither, and the base exists so they say so by
        // saying nothing at all.
        $command = new PlainCommand;

        $this->assertSame([], $command->arguments());
        $this->assertSame([], $command->options());
    }

    public function test_the_attribute_carries_the_name_and_an_optional_description(): void
    {
        $named = new AsCommand('demo:thing');

        $this->assertSame('demo:thing', $named->name);
        $this->assertSame('', $named->description);
    }

    public function test_the_exit_codes_are_the_shell_s_own(): void
    {
        // Zero is success everywhere; anything else stops a && chain.
        $this->assertSame(0, ExitCode::Success->value);
        $this->assertSame(1, ExitCode::Failure->value);
        $this->assertSame(2, ExitCode::Invalid->value);
    }
}

#[AsCommand(name: 'demo:plain')]
final class PlainCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}
