<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Testing\InputContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** The hand-built input against the published contract. */
#[CoversClass(ArrayInput::class)]
final class ArrayInputTest extends InputContractTestCase
{
    protected function input(
        CommandInterface $command,
        array $arguments = [],
        array $options = [],
        array $flags = [],
    ): InputInterface {
        return ArrayInput::forCommand($command, $arguments, $options, $flags);
    }

    public function test_without_the_declarations_no_defaults_are_filled_in(): void
    {
        // The plain constructor is the raw bag, and says so: it knows nothing
        // about what was declared. forCommand() is the one that does.
        $input = new ArrayInput;

        $this->assertSame('', $input->option('table'));
        $this->assertSame('', $input->argument('shape'));
    }
}
