<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * What a stored column means as a tick. MariaDB hands a TINYINT back as "1",
 * sqlite as 1, and a source that computed the value hands back a real bool;
 * all three are the same checked box. Everything else is unchecked, which
 * includes the null of a column that has never been written.
 */
final class Flag
{
    private const TRUE = ['1', 'true', 'on', 'yes'];

    public static function of(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value)
            && in_array(strtolower(trim((string) $value)), self::TRUE, true);
    }
}
