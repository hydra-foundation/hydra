<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole\Tests\Unit;

use Hydra\Console\Contracts\CommandInterface;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Testing\InputContractTestCase;
use Hydra\SymfonyConsole\SymfonyCommand;
use Hydra\SymfonyConsole\SymfonyInput;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Input\ArrayInput as SymfonyArrayInput;

/**
 * The same contract, answered by a real parsed command line.
 *
 * The definition comes from {@see SymfonyCommand}, so what is exercised here is
 * the declaration translation the adapter actually performs — not a definition
 * written out a second time to match it.
 */
#[CoversClass(SymfonyInput::class)]
final class SymfonyInputContractTest extends InputContractTestCase
{
    protected function input(
        CommandInterface $command,
        array $arguments = [],
        array $options = [],
        array $flags = [],
    ): InputInterface {
        $given = $arguments;

        foreach ($options as $name => $value) {
            $given['--' . $name] = $value;
        }

        foreach ($flags as $name) {
            $given['--' . $name] = true;
        }

        $definition = (new SymfonyCommand($command))->getDefinition();

        return new SymfonyInput(new SymfonyArrayInput($given, $definition));
    }
}
