<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole\Tests\Unit;

use Hydra\Console\Argument;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\SymfonyConsole\SymfonyCommand;
use Hydra\SymfonyConsole\SymfonyInput;
use Hydra\SymfonyConsole\SymfonyOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyBaseCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The adapter driven through Symfony's own tester, which is the only place in
 * the tree that still names Symfony in a test — deliberately. Everything else
 * asserts against {@see \Hydra\Console\Testing\FakeOutput}; this asserts that
 * what the fake stands in for is really what Symfony does.
 */
#[CoversClass(SymfonyCommand::class)]
#[CoversClass(SymfonyInput::class)]
#[CoversClass(SymfonyOutput::class)]
final class SymfonyCommandTest extends TestCase
{
    public function test_it_takes_its_name_and_description_from_the_attribute(): void
    {
        $command = new SymfonyCommand(new RecordingCommand);

        $this->assertSame('demo:record', $command->getName());
        $this->assertSame('Record what it was given', $command->getDescription());
    }

    public function test_a_required_argument_reaches_the_command(): void
    {
        $inner = new RecordingCommand;
        $tester = new CommandTester(new SymfonyCommand($inner));

        $tester->execute(['name' => 'ada']);

        $this->assertSame('ada', $inner->seenArgument);
    }

    public function test_an_optional_argument_falls_back_to_its_default(): void
    {
        $inner = new RecordingCommand;
        $tester = new CommandTester(new SymfonyCommand($inner));

        $tester->execute(['name' => 'ada']);

        $this->assertSame('none', $inner->seenOptional);
    }

    public function test_a_flag_reads_true_when_given_and_false_when_not(): void
    {
        $given = new RecordingCommand;
        (new CommandTester(new SymfonyCommand($given)))->execute(['name' => 'ada', '--force' => true]);
        $this->assertTrue($given->seenFlag);

        $absent = new RecordingCommand;
        (new CommandTester(new SymfonyCommand($absent)))->execute(['name' => 'ada']);
        $this->assertFalse($absent->seenFlag);
    }

    public function test_a_value_option_reaches_the_command(): void
    {
        $inner = new RecordingCommand;
        $tester = new CommandTester(new SymfonyCommand($inner));

        $tester->execute(['name' => 'ada', '--table' => 'posts']);

        $this->assertSame('posts', $inner->seenOption);
    }

    public function test_a_value_option_falls_back_to_its_declared_default(): void
    {
        $inner = new RecordingCommand;
        $tester = new CommandTester(new SymfonyCommand($inner));

        $tester->execute(['name' => 'ada']);

        $this->assertSame('guessed', $inner->seenOption);
    }

    public function test_the_exit_code_becomes_the_process_status(): void
    {
        // The enum is Hydra's; what the shell reads is an int, and a `&&` chain
        // hangs on that translation being right.
        $tester = new CommandTester(new SymfonyCommand(new FailingCommand));

        $this->assertSame(SymfonyBaseCommand::FAILURE, $tester->execute([]));
    }

    public function test_output_reaches_the_terminal(): void
    {
        $tester = new CommandTester(new SymfonyCommand(new TalkativeCommand));
        $tester->execute([]);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('it worked', $display);
        $this->assertStringContainsString('a plain line', $display);
        $this->assertStringContainsString('Header', $display);
        $this->assertStringContainsString('cell', $display);
    }

    public function test_confirm_is_answered_by_the_terminal(): void
    {
        $inner = new ConfirmingCommand;
        $tester = new CommandTester(new SymfonyCommand($inner));
        $tester->setInputs(['no']);

        $tester->execute([]);

        $this->assertFalse($inner->answer);
    }
}

#[AsCommand(name: 'demo:record', description: 'Record what it was given')]
final class RecordingCommand extends Command
{
    public string $seenArgument = '';
    public string $seenOptional = '';
    public string $seenOption = '';
    public bool $seenFlag = false;

    public function arguments(): array
    {
        return [
            Argument::required('name', 'Who'),
            Argument::optional('shape', 'Optional shape', 'none'),
        ];
    }

    public function options(): array
    {
        return [
            Option::flag('force', 'f', 'Overwrite'),
            Option::value('table', 't', 'The table', 'guessed'),
        ];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $this->seenArgument = $input->argument('name');
        $this->seenOptional = $input->argument('shape');
        $this->seenOption = $input->option('table');
        $this->seenFlag = $input->flag('force');

        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:fails')]
final class FailingCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Failure;
    }
}

#[AsCommand(name: 'demo:talks')]
final class TalkativeCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $output->success('it worked');
        $output->write('a plain line');
        $output->table(['Header'], [['cell']]);

        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:confirms')]
final class ConfirmingCommand extends Command
{
    public ?bool $answer = null;

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $this->answer = $output->confirm('Really?', true);

        return ExitCode::Success;
    }
}
