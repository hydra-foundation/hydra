<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole\Tests\Unit;

use Hydra\SymfonyConsole\SymfonyInput;
use Hydra\SymfonyConsole\SymfonyOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The two adapters against real Symfony objects: everything a command can say,
 * and everything it can be asked about what was typed.
 */
#[CoversClass(SymfonyInput::class)]
#[CoversClass(SymfonyOutput::class)]
final class AdaptersTest extends TestCase
{
    /** @param array<string, mixed> $given */
    private function input(array $given, ?InputDefinition $definition = null): SymfonyInput
    {
        $definition ??= new InputDefinition([
            new InputArgument('name', InputArgument::OPTIONAL),
            new InputOption('force', 'f', InputOption::VALUE_NONE),
            new InputOption('table', 't', InputOption::VALUE_REQUIRED),
        ]);

        return new SymfonyInput(new ArrayInput($given, $definition));
    }

    public function test_it_reads_an_argument_that_was_typed(): void
    {
        $this->assertSame('ada', $this->input(['name' => 'ada'])->argument('name'));
        $this->assertTrue($this->input(['name' => 'ada'])->hasArgument('name'));
    }

    public function test_an_argument_that_was_not_typed_is_absent_and_defaults(): void
    {
        // Declared but untyped is null in Symfony, and (string) null is a
        // deprecation now and an error later.
        $input = $this->input([]);

        $this->assertFalse($input->hasArgument('name'));
        $this->assertSame('fallback', $input->argument('name', 'fallback'));
    }

    public function test_an_argument_the_command_never_declared_defaults(): void
    {
        $input = $this->input([]);

        $this->assertFalse($input->hasArgument('nope'));
        $this->assertSame('fallback', $input->argument('nope', 'fallback'));
    }

    public function test_it_reads_a_value_option_and_defaults_when_absent(): void
    {
        $this->assertSame('posts', $this->input(['--table' => 'posts'])->option('table'));
        $this->assertSame('guess', $this->input([])->option('table', 'guess'));
        $this->assertSame('guess', $this->input([])->option('undeclared', 'guess'));
    }

    public function test_a_flag_is_true_only_when_given(): void
    {
        $this->assertTrue($this->input(['--force' => true])->flag('force'));
        $this->assertFalse($this->input([])->flag('force'));
        $this->assertFalse($this->input([])->flag('undeclared'));
    }

    public function test_every_way_of_speaking_reaches_the_terminal(): void
    {
        $buffer = new BufferedOutput;
        $output = new SymfonyOutput(new SymfonyStyle(new ArrayInput([]), $buffer));

        $output->write('a plain line');
        $output->success('it worked');
        $output->error('it did not');
        $output->warning('be careful');
        $output->note('by the way');
        $output->table(['Header'], [['cell']]);
        $output->listing(['first', 'second']);

        $display = $buffer->fetch();

        foreach (['a plain line', 'it worked', 'it did not', 'be careful', 'by the way', 'Header', 'cell', 'first', 'second'] as $expected) {
            $this->assertStringContainsString($expected, $display, "\"{$expected}\" never reached the terminal.");
        }
    }

    public function test_a_question_is_answered_from_the_terminal(): void
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "ada\n");
        rewind($stream);
        $input->setStream($stream);

        $output = new SymfonyOutput(new SymfonyStyle($input, new BufferedOutput));

        $this->assertSame('ada', $output->ask('Your name?'));

        fclose($stream);
    }

    public function test_a_question_falls_back_to_its_default_without_a_terminal(): void
    {
        // Non-interactive is how this runs under cron and in CI, and a command
        // that blocked there would hang the job rather than fail it.
        $input = new ArrayInput([]);
        $input->setInteractive(false);

        $output = new SymfonyOutput(new SymfonyStyle($input, new BufferedOutput));

        $this->assertSame('anonymous', $output->ask('Your name?', 'anonymous'));
        $this->assertTrue($output->confirm('Proceed?', true));
        $this->assertFalse($output->confirm('Proceed?', false));
    }
}
