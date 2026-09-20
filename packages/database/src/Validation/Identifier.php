<?php

declare(strict_types=1);

namespace Hydra\Database\Validation;

use InvalidArgumentException;

/**
 * Table and column names cannot travel as bound parameters, so the rules that
 * interpolate them accept only a plain unquoted identifier and refuse the rest
 * rather than trying to escape it for a dialect they were not told about.
 */
trait Identifier
{
    private function assertIdentifier(string $name, string $kind): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Not a usable {$kind} name: {$name}");
        }

        return $name;
    }
}
