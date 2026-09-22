<?php

declare(strict_types=1);

namespace Hydra\Console;

use Hydra\Console\Contracts\CommandInterface;

/**
 * The base most commands extend: the two declarations, empty.
 *
 * Extending is a convenience and not the contract — a command that would rather
 * implement {@see CommandInterface} directly loses nothing by doing so.
 */
abstract class Command implements CommandInterface
{
    /** @return list<Argument> */
    public function arguments(): array
    {
        return [];
    }

    /** @return list<Option> */
    public function options(): array
    {
        return [];
    }
}
