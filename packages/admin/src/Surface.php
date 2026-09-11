<?php

declare(strict_types=1);

namespace Hydra\Admin;

/**
 * Surface
 *
 * Where a field appears. One field declaration projects onto many surfaces, all
 * of them read-only renderings: a writable control is not a Surface, because a
 * form needs the stored value back, not a formatted one.
 */
enum Surface: string
{
    case List = 'list';
    case Show = 'show';
}
