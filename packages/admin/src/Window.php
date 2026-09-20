<?php

declare(strict_types=1);

namespace Hydra\Admin;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A period resolved against the clock: the two instants a widget counts
 * between, and the words for them.
 *
 * Resolved once per request and handed to every card, so that two widgets on
 * one dashboard cannot disagree about when "now" was — which is what happens
 * when each reads the clock for itself either side of a midnight.
 *
 * A null bound is open: all time has neither, and a rolling period has no end.
 */
final readonly class Window
{
    public function __construct(
        public Period $period,
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
        /**
         * When the window was resolved, which is where an open end actually
         * falls. Told rather than read, for the reason above: a window that
         * looked up the clock to describe itself could name an end its own
         * query had not counted up to.
         */
        public ?DateTimeImmutable $now = null,
    ) {}

    public function label(): string
    {
        return $this->period->label();
    }

    /**
     * The window in dates, or null when it has no bounds to state.
     *
     * What the select cannot say: "Last 7 days" is the name of a choice, and
     * this is the stretch it turned out to mean. Rendered beside the grid, it
     * is also the only thing on the page that distinguishes one rolling period
     * from another at a glance.
     */
    public function range(): ?string
    {
        if ($this->since === null) {
            return null;
        }

        // An open end runs up to now. The bound on a closed one is exclusive,
        // so the last instant inside it is a moment earlier — without which a
        // single day reads as spanning two.
        $last = $this->until?->modify('-1 second') ?? $this->now;

        if ($last === null) {
            return 'Since ' . $this->since->format('j M Y');
        }

        if ($this->since->format('j M Y') === $last->format('j M Y')) {
            return $last->format('j M Y');
        }

        // The year is stated once where both ends share it, and twice where
        // they do not — twelve months back is a different year for most of it.
        $start = $this->since->format('Y') === $last->format('Y')
            ? $this->since->format('j M')
            : $this->since->format('j M Y');

        return $start . ' – ' . $last->format('j M Y');
    }

    /**
     * The window as a condition over one column, and the values to bind to it.
     *
     * A condition and not a WHERE clause, so it composes with whatever else the
     * query asks; all time comes back as a tautology rather than as an empty
     * string, for the same reason.
     *
     * The bounds are converted to UTC before they are formatted, because that is
     * what the column holds. The window itself is in the reader's zone — that is
     * where a day starts and ends — and comparing the one against the other
     * unconverted is how an evening's rows come to fall outside "today".
     *
     * The bounds are bound, but the column is written into the SQL, so it is
     * held to a column's shape: this is for a name the developer typed, not one
     * that arrived in a request.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function condition(string $column, string $format = 'Y-m-d H:i:s'): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException("\"{$column}\" is not a column a window can be read over.");
        }

        $sql = [];
        $bindings = [];

        $utc = new DateTimeZone('UTC');

        if ($this->since !== null) {
            $sql[] = "{$column} >= ?";
            $bindings[] = $this->since->setTimezone($utc)->format($format);
        }

        if ($this->until !== null) {
            $sql[] = "{$column} < ?";
            $bindings[] = $this->until->setTimezone($utc)->format($format);
        }

        return $sql === [] ? ['1 = 1', []] : [implode(' AND ', $sql), $bindings];
    }
}
