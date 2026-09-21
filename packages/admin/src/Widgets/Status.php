<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

/** How a health card's subject is doing, and the ink that says so. */
enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Down = 'down';

    /** The class the figure wears; Ok is the card's own ink and needs none. */
    public function tone(): string
    {
        return match ($this) {
            self::Ok => '',
            self::Warning => 'is-warning',
            self::Down => 'is-down',
        };
    }
}
