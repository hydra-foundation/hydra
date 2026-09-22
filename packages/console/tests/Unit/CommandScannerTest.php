<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\CommandScanner;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandScanner::class)]
final class CommandScannerTest extends TestCase
{
    public function test_it_reads_the_name_and_description_from_the_class(): void
    {
        $described = (new CommandScanner)->describe(GreetCommand::class);

        $this->assertSame('demo:greet', $described->name);
        $this->assertSame('Say hello', $described->description);
    }

    public function test_it_reads_them_from_an_instance_too(): void
    {
        $this->assertSame('demo:greet', (new CommandScanner)->describe(new GreetCommand)->name);
    }

    public function test_describing_a_class_without_the_attribute_fails_loudly(): void
    {
        // A registration mistake, not a runtime condition: the alternative is a
        // command registered under an empty name, which nothing can invoke.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must carry');

        (new CommandScanner)->describe(UnnamedCommand::class);
    }

    public function test_it_maps_names_to_classes(): void
    {
        $names = (new CommandScanner)->scan([GreetCommand::class, PartCommand::class]);

        $this->assertSame(['demo:greet' => GreetCommand::class, 'demo:part' => PartCommand::class], $names);
    }

    public function test_two_commands_under_one_name_fail_loudly(): void
    {
        // Otherwise the last registration wins and the other command is simply
        // unreachable, with nothing said about it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('are named "demo:greet"');

        (new CommandScanner)->scan([GreetCommand::class, AlsoGreetCommand::class]);
    }

    public function test_the_name_is_readable_without_constructing_the_command(): void
    {
        // The whole point of the attribute: a console lists migrate:fresh
        // without opening the database connection its constructor would want.
        $this->assertSame('demo:explodes', (new CommandScanner)->describe(ExplodingCommand::class)->name);
    }
}

#[AsCommand(name: 'demo:greet', description: 'Say hello')]
final class GreetCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:part')]
final class PartCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:greet')]
final class AlsoGreetCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}

final class UnnamedCommand extends Command
{
    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}

#[AsCommand(name: 'demo:explodes')]
final class ExplodingCommand extends Command
{
    public function __construct()
    {
        throw new \RuntimeException('Constructing this command is the thing under test.');
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        return ExitCode::Success;
    }
}
