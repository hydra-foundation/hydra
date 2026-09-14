<?php

declare(strict_types=1);

namespace Hydra\Admin\Events;

/**
 * Something a module did, announced.
 *
 * Every admin event derives from this, and {@see \Hydra\Event\ListenerProvider}
 * matches a listener to an event's subtypes, so one registration against this
 * class hears everything the admin will ever announce, including whatever is
 * added to it later.
 *
 * No event carries who did it. The admin knows what a screen may be reached
 * with (an ability) and not who reached it, and reaching for the guard here
 * would make an audit trail's worth of coupling out of a package that does not
 * otherwise depend on authentication at all. A listener runs inside the request
 * and can ask.
 */
abstract class AdminEvent
{
    protected function __construct(public readonly string $module) {}

    /**
     * What happened, as a stable key rather than a sentence: this is what an
     * audit line is grouped, filtered and alerted on.
     */
    abstract public function action(): string;

    /**
     * The line's own detail.
     *
     * What a log may hold, which is not the same as what the event holds. A
     * written row's values are on the event for a listener that wants them and
     * are deliberately not in here: a module's fields are whatever the
     * application declared, and a listener that logged them wholesale would
     * copy a password reset into the log the first time somebody edited one.
     *
     * @return array<string, mixed>
     */
    abstract public function context(): array;
}
