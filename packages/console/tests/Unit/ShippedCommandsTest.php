<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Argument;
use Hydra\Console\CommandScanner;
use Hydra\Console\Commands\KeyGenerateCommand;
use Hydra\Console\Commands\MakeMigrationCommand;
use Hydra\Console\Commands\MigrateFreshCommand;
use Hydra\Console\Commands\MigrateRunCommand;
use Hydra\Console\Commands\MigrateStatusCommand;
use Hydra\Console\Commands\RouteCacheClearCommand;
use Hydra\Console\Commands\RouteCacheCommand;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Option;
use Hydra\Database\MigrationRunner;
use Hydra\Http\RouteCache;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every shipped command declares about itself.
 *
 * These exist because of a gap the fakes open up. A test drives a command with
 * {@see \Hydra\Console\Testing\ArrayInput}, which answers whatever the test puts
 * in it — so a command that reads `--force` while declaring `--overwrite` still
 * passes its own unit test and still fails at a real terminal. Nothing else
 * holds a command's declaration against the names it reads.
 */
#[CoversClass(KeyGenerateCommand::class)]
#[CoversClass(MakeMigrationCommand::class)]
#[CoversClass(MigrateFreshCommand::class)]
#[CoversClass(MigrateRunCommand::class)]
#[CoversClass(MigrateStatusCommand::class)]
#[CoversClass(RouteCacheClearCommand::class)]
#[CoversClass(RouteCacheCommand::class)]
final class ShippedCommandsTest extends TestCase
{
    /** @return iterable<string, array{CommandInterface, string}> */
    public static function shippedCommands(): iterable
    {
        $pdo = new PDO('sqlite::memory:');
        $runner = new MigrationRunner($pdo, sys_get_temp_dir(), 'sqlite');
        $cache = new RouteCache(sys_get_temp_dir() . '/hydra-declared-routes.php', []);

        yield 'key:generate' => [new KeyGenerateCommand('/dev/null'), 'key:generate'];
        yield 'make:migration' => [new MakeMigrationCommand(sys_get_temp_dir()), 'make:migration'];
        yield 'migrate:fresh' => [new MigrateFreshCommand($runner, debug: true), 'migrate:fresh'];
        yield 'migrate:run' => [new MigrateRunCommand($runner), 'migrate:run'];
        yield 'migrate:status' => [new MigrateStatusCommand($runner), 'migrate:status'];
        yield 'route:cache' => [new RouteCacheCommand($cache, []), 'route:cache'];
        yield 'route:cache:clear' => [new RouteCacheClearCommand($cache), 'route:cache:clear'];
    }

    #[DataProvider('shippedCommands')]
    public function test_it_is_named_and_described(CommandInterface $command, string $name): void
    {
        $described = (new CommandScanner)->describe($command);

        $this->assertSame($name, $described->name);
        $this->assertNotSame('', $described->description, 'A command with no description is invisible in `list`.');
    }

    #[DataProvider('shippedCommands')]
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

    #[DataProvider('shippedCommands')]
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

    public function test_the_force_flag_is_declared_wherever_it_is_read(): void
    {
        // The specific mismatch the fakes cannot catch: every command below
        // branches on flag('force'), so every one of them has to declare it.
        foreach ([
            new KeyGenerateCommand('/dev/null'),
            new MigrateFreshCommand(new MigrationRunner(new PDO('sqlite::memory:'), sys_get_temp_dir(), 'sqlite'), debug: true),
        ] as $command) {
            $declared = array_map(static fn (Option $o): string => $o->name, $command->options());

            $this->assertContains('force', $declared, $command::class . ' reads --force without declaring it.');
        }
    }
}
