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
    ) {}

    public function label(): string
    {
        return $this->period->label();
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
