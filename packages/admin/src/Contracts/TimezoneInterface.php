<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use DateTimeZone;

/**
 * What zone the person reading this request is in.
 *
 * Rows are stored in UTC and the process runs in UTC, because an instant has to
 * mean one thing no matter which machine reads it. This is the other end of
 * that: the one place where a stored instant becomes a time of day, and where
 * "today" acquires a midnight.
 *
 * Resolved per request, so an implementation is free to answer from the signed-
 * in account's own preference.
 */
interface TimezoneInterface
{
    public function zone(): DateTimeZone;
}
