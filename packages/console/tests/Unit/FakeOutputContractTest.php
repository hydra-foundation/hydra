<?php

declare(strict_types=1);

namespace Hydra\Console\Tests\Unit;

use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Console\Testing\OutputContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FakeOutput::class)]
final class FakeOutputContractTest extends OutputContractTestCase
{
    protected function unattended(): OutputInterface
    {
        return new FakeOutput;
    }

    protected function said(OutputInterface $output): string
    {
        assert($output instanceof FakeOutput);

        $headers = array_merge([], ...array_column($output->tables(), 'headers'));

        return implode("\n", [...$output->lines(), ...$headers]);
    }
}
