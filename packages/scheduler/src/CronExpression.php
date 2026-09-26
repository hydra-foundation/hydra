<?php

declare(strict_types=1);

namespace Hydra\Scheduler;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The five-field cron expression: minute, hour, day of month, month, day of
 * week. Each field takes `*`, a number, a range `a-b`, a list, and a step
 * `/n` on a star, a range or a start. Names (`MON`, `JAN`) and the `@daily`
 * shorthands are not supported; the schedule's own helpers cover those.
 *
 * Matched against whatever wall clock it is handed, so the zone is the
 * caller's decision.
 */
final readonly class CronExpression
{
    private const FIELDS = [
        'minute' => [0, 59],
        'hour' => [0, 23],
        'day of month' => [1, 31],
        'month' => [1, 12],
        'day of week' => [0, 7],
    ];

    private const SEARCH_SECONDS = 5 * 366 * 86400;

    /**
     * @param array<string, array<int, true>> $allowed per field, the values that match
     * @param bool $eitherDay both day fields are restricted, so matching one is enough
     */
    private function __construct(
        public string $expression,
        private array $allowed,
        private bool $eitherDay,
    ) {}

    public static function parse(string $expression): self
    {
        $parts = preg_split('/\s+/', trim($expression));

        if ($parts === false || count($parts) !== count(self::FIELDS)) {
            throw new InvalidArgumentException(
                "Cron expression \"{$expression}\" needs five fields: minute, hour, day of month, month, day of week.",
            );
        }

        $allowed = [];

        foreach (array_keys(self::FIELDS) as $i => $name) {
            $allowed[$name] = self::field($expression, $name, $parts[$i]);
        }

        // Sunday is both 0 and 7.
        if (isset($allowed['day of week'][7])) {
            $allowed['day of week'][0] = true;
        }

        // Cron's own rule: when both day fields are restricted, a day matching
        // either runs the job. "0 4 1 * MON" is the 1st and every Monday.
        $eitherDay = !str_starts_with($parts[2], '*') && !str_starts_with($parts[4], '*');

        return new self(trim($expression), $allowed, $eitherDay);
    }

    public function isDue(DateTimeInterface $at): bool
    {
        if (
            !isset($this->allowed['minute'][(int) $at->format('i')])
            || !isset($this->allowed['hour'][(int) $at->format('G')])
            || !isset($this->allowed['month'][(int) $at->format('n')])
        ) {
            return false;
        }

        return $this->dayMatches($at);
    }

    /**
     * The first minute after $after that this matches, in $after's zone, or
     * null when none does within five years. A wall-clock time the clocks
     * skip is skipped here too, as the minute tick would skip it.
     */
    public function next(DateTimeInterface $after): ?DateTimeImmutable
    {
        $zone = $after->getTimezone();
        $limit = $after->getTimestamp() + self::SEARCH_SECONDS;
        // Minutes and hours move by elapsed seconds, so the hour the clocks
        // go back is walked through once rather than re-entered.
        $at = (int) (intdiv($after->getTimestamp(), 60) * 60) + 60;

        while ($at <= $limit) {
            $local = (new DateTimeImmutable('@' . $at))->setTimezone($zone);

            if (!isset($this->allowed['month'][(int) $local->format('n')])) {
                $at = $local->setDate((int) $local->format('Y'), (int) $local->format('n') + 1, 1)->setTime(0, 0)->getTimestamp();
            } elseif (!$this->dayMatches($local)) {
                $at = $local->modify('tomorrow')->getTimestamp();
            } elseif (!isset($this->allowed['hour'][(int) $local->format('G')])) {
                $at += (60 - (int) $local->format('i')) * 60;
            } elseif (!isset($this->allowed['minute'][(int) $local->format('i')])) {
                $at += 60;
            } else {
                return $local;
            }
        }

        return null;
    }

    private function dayMatches(DateTimeInterface $at): bool
    {
        $dayOfMonth = isset($this->allowed['day of month'][(int) $at->format('j')]);
        $dayOfWeek = isset($this->allowed['day of week'][(int) $at->format('w')]);

        return $this->eitherDay ? $dayOfMonth || $dayOfWeek : $dayOfMonth && $dayOfWeek;
    }

    /** @return array<int, true> */
    private static function field(string $expression, string $name, string $field): array
    {
        [$min, $max] = self::FIELDS[$name];
        $values = [];

        foreach (explode(',', $field) as $item) {
            if (preg_match('~^(\*|\d+(?:-\d+)?)(?:/(\d+))?$~', $item, $m) !== 1) {
                throw self::invalid($expression, $name, $field);
            }

            $step = isset($m[2]) ? (int) $m[2] : 1;

            if ($m[1] === '*') {
                [$from, $to] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$from, $to] = array_map('intval', explode('-', $m[1]));
            } else {
                $from = (int) $m[1];
                // "5/15" runs from 5 to the end of the field, as cron reads it.
                $to = isset($m[2]) ? $max : $from;
            }

            if ($step < 1 || $from < $min || $to > $max || $from > $to) {
                throw self::invalid($expression, $name, $field);
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        return $values;
    }

    private static function invalid(string $expression, string $name, string $field): InvalidArgumentException
    {
        [$min, $max] = self::FIELDS[$name];

        return new InvalidArgumentException(
            "Cron expression \"{$expression}\" has a {$name} field \"{$field}\" outside {$min}-{$max}, or not in the form *, n, a-b, a,b or a step /n.",
        );
    }
}
