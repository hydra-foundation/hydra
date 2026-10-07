<?php

declare(strict_types=1);

namespace Hydra\Admin;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;

/**
 * The days a list is narrowed to: a From and a To, either of them open.
 *
 * Both ends are whole days and both are inclusive, because that is how a
 * person asks ("the 1st to the 5th" means the 5th as well). They are days in
 * the reader's zone, for the reason {@see Period} gives: a midnight taken from
 * UTC would move an evening's rows into tomorrow. A datetime column is then
 * read half-open in UTC, from the first day's midnight up to, but not
 * including, the midnight after the last.
 *
 * A date column has no time of day to be in anyone's zone, so its days are
 * compared as they are written.
 */
final readonly class DateRange
{
    private const DAY = 'Y-m-d';

    private function __construct(
        public FieldType $type,
        /** Midnight on the first day, in the reader's zone; null when open. */
        public ?DateTimeImmutable $from,
        /** Midnight on the last day, in the reader's zone; null when open. */
        public ?DateTimeImmutable $to,
    ) {}

    /**
     * The range two typed days ask for, or null when they ask for nothing.
     *
     * Normalised, never refused, like a page number: a day that is not a real
     * one is dropped, and ends given backwards are swapped, since nobody who
     * typed them meant a range that holds nothing.
     */
    public static function fromDays(?string $from, ?string $to, FieldType $type, DateTimeZone $zone): ?self
    {
        if ($type !== FieldType::Date && $type !== FieldType::DateTime) {
            throw new LogicException(sprintf(
                'A %s field cannot be narrowed by days; only a date or a datetime has a range.',
                $type->value,
            ));
        }

        $first = self::day($from, $zone);
        $last = self::day($to, $zone);

        if ($first === null && $last === null) {
            return null;
        }

        if ($first !== null && $last !== null && $first > $last) {
            [$first, $last] = [$last, $first];
        }

        return new self($type, $first, $last);
    }

    /** The first day as the date input and the URL spell it. */
    public function fromValue(): ?string
    {
        return $this->from?->format(self::DAY);
    }

    /** The last day as the date input and the URL spell it. */
    public function toValue(): ?string
    {
        return $this->to?->format(self::DAY);
    }

    /**
     * The range as a condition over one column, and the values to bind to it.
     * See {@see Window::condition()}, which this reads the same way.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function condition(string $column): array
    {
        ColumnName::check($column, 'a date range');

        $sql = [];
        $bindings = [];

        if ($this->type === FieldType::Date) {
            if ($this->from !== null) {
                $sql[] = "{$column} >= ?";
                $bindings[] = $this->from->format(self::DAY);
            }

            if ($this->to !== null) {
                $sql[] = "{$column} <= ?";
                $bindings[] = $this->to->format(self::DAY);
            }

            return [implode(' AND ', $sql), $bindings];
        }

        $utc = new DateTimeZone('UTC');

        if ($this->from !== null) {
            $sql[] = "{$column} >= ?";
            $bindings[] = $this->from->setTimezone($utc)->format('Y-m-d H:i:s');
        }

        if ($this->to !== null) {
            $sql[] = "{$column} < ?";
            $bindings[] = $this->dayAfter()->setTimezone($utc)->format('Y-m-d H:i:s');
        }

        return [implode(' AND ', $sql), $bindings];
    }

    /**
     * Whether an instant falls inside, for a source that holds its rows in
     * memory rather than asking a database. The same edges as condition().
     */
    public function contains(DateTimeInterface $instant): bool
    {
        if ($this->type === FieldType::Date) {
            $day = $instant->format(self::DAY);

            return ($this->from === null || $day >= $this->from->format(self::DAY))
                && ($this->to === null || $day <= $this->to->format(self::DAY));
        }

        return ($this->from === null || $instant >= $this->from)
            && ($this->to === null || $instant < $this->dayAfter());
    }

    /**
     * Midnight after the last day, read off the calendar rather than added as
     * 24 hours: the day the clocks go back is 25 hours long.
     */
    private function dayAfter(): DateTimeImmutable
    {
        /** @var DateTimeImmutable $to only called with a closed end */
        $to = $this->to;

        return $to->modify('+1 day');
    }

    private static function day(?string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!' . self::DAY, $value, $zone);

        // createFromFormat rolls 30 February over into March rather than
        // failing, and reads "2026-1-5" as a day, so one only counts if it
        // comes back exactly as it was typed.
        return $day !== false && $day->format(self::DAY) === $value ? $day : null;
    }
}
