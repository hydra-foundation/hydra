<?php

declare(strict_types=1);

namespace Hydra\SymfonyConsole\Tests\Unit;

use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\Testing\OutputContractTestCase;
use Hydra\SymfonyConsole\SymfonyOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The same contract, drawn by Symfony. Unattended is --no-interaction, which is
 * what a pipe, a cron run and CI all look like to the question helper.
 */
#[CoversClass(SymfonyOutput::class)]
final class SymfonyOutputContractTest extends OutputContractTestCase
{
    private BufferedOutput $buffer;

    protected function unattended(): OutputInterface
    {
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $this->buffer = new BufferedOutput;

        return new SymfonyOutput(new SymfonyStyle($input, $this->buffer));
    }

    protected function said(OutputInterface $output): string
    {
        return $this->buffer->fetch();
    }
}
