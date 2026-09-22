<?php

declare(strict_types=1);

namespace Hydra\Database\Tests\Unit;

use Hydra\Console\Testing\CommandContractTestCase;
use Hydra\Database\Console\MigrateFreshCommand;
use Hydra\Database\Console\MigrateRunCommand;
use Hydra\Database\Console\MigrateStatusCommand;
use Hydra\Database\MigrationRunner;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MigrateFreshCommand::class)]
#[CoversClass(MigrateRunCommand::class)]
#[CoversClass(MigrateStatusCommand::class)]
final class ShippedCommandsTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        $runner = self::runner();

        yield 'migrate:fresh' => new MigrateFreshCommand($runner, debug: true);
        yield 'migrate:run' => new MigrateRunCommand($runner);
        yield 'migrate:status' => new MigrateStatusCommand($runner);
    }

    public function test_migrate_fresh_declares_the_force_it_reads(): void
    {
        $this->assertDeclaresOption(new MigrateFreshCommand(self::runner(), debug: true), 'force');
    }

    private static function runner(): MigrationRunner
    {
        return new MigrationRunner(new PDO('sqlite::memory:'), sys_get_temp_dir(), 'sqlite');
    }
}
