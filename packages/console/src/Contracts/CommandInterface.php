<?php

declare(strict_types=1);

namespace Hydra\Console\Contracts;

use Hydra\Console\Argument;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;

/**
 * One thing the console can be asked to do.
 *
 * The name and the description live on {@see \Hydra\Console\Attributes\AsCommand}
 * rather than here, so a console can list what it offers without building
 * anything. What is left is the part that needs the object: what it accepts,
 * and what it does.
 *
 * {@see \Hydra\Console\Command} implements the two declarations as empty, which
 * is what most commands want.
 */
interface CommandInterface
{
    /** @return list<Argument> in the order they are typed */
    public function arguments(): array;

    /** @return list<Option> */
    public function options(): array;

    public function execute(InputInterface $input, OutputInterface $output): ExitCode;
}
