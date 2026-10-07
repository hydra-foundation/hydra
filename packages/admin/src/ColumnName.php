<?php

declare(strict_types=1);

namespace Hydra\Admin;

use InvalidArgumentException;

/**
 * The shape a column has to have before it is written into SQL.
 *
 * Bounds are bound, but a column cannot be: no driver takes an identifier as
 * a parameter. So the name a condition is read over is held to a column's
 * shape, optionally qualified by a table alias. It is for a name the developer
 * typed, not one that arrived in a request.
 *
 * @internal shared by {@see Window} and {@see DateRange}, so the two cannot
 *           drift apart on what counts as a column
 */
final class ColumnName
{
    private const SHAPE = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** @param string $readBy what the column is read over, for the message: "a window" */
    public static function check(string $column, string $readBy): void
    {
        if (preg_match(self::SHAPE, $column) !== 1) {
            throw new InvalidArgumentException("\"{$column}\" is not a column {$readBy} can be read over.");
        }
    }
}
