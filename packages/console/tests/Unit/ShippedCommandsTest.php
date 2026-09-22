<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Commands\KeyGenerateCommand;
use Hydra\Console\Commands\MakeMigrationCommand;
use Hydra\Console\Testing\CommandContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(KeyGenerateCommand::class)]
#[CoversClass(MakeMigrationCommand::class)]
final class ShippedCommandsTest extends CommandContractTestCase
{
    public static function commands(): iterable
    {
        yield 'key:generate' => new KeyGenerateCommand('/dev/null');
        yield 'make:migration' => new MakeMigrationCommand(sys_get_temp_dir());
    }

    public function test_key_generate_declares_the_force_it_reads(): void
    {
        $this->assertDeclaresOption(new KeyGenerateCommand('/dev/null'), 'force');
    }
}
