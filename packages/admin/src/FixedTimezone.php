<?php

declare(strict_types=1);

namespace Hydra\Admin;

use DateTimeZone;
use Hydra\Admin\Contracts\TimezoneInterface;

/**
 * One zone for everybody, which is what an admin with no preference to read
 * needs. UTC by default: the same zone the rows are stored in, so an
 * application that binds nothing sees exactly what the database holds.
 */
final readonly class FixedTimezone implements TimezoneInterface
{
    private DateTimeZone $zone;

    public function __construct(string $zone = 'UTC')
    {
        $this->zone = new DateTimeZone($zone);
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }
}
