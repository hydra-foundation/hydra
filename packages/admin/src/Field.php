<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Hydra\View\HtmlView;
use LogicException;

/**
 * One column of a module, declared once and projected onto every surface that
 * wants it: a table cell, a detail row, an export column. Display rules are
 * declared per surface.
 */
final class Field
{
    private string $label;

    /**
     * Every surface: a declared column is part of the export unless the module
     * says otherwise, because the alternative is an export that silently ships
     * fewer columns than the module declared.
     *
     * @var list<Surface>
     */
    private array $surfaces = [Surface::List, Surface::Show, Surface::Export];

    private bool $sortable = false;
    private bool $searchable = false;
    private bool $filterable = false;

    private int $decimals = 0;
    private bool $grouped = false;
    private string $suffix = '';
    private bool $relative = false;

    /** @var array<string, int> keyed by Surface->value */
    private array $truncations = [];

    /** @var array<string, array{fn: Closure, decorates: bool}> keyed by Surface->value */
    private array $formatters = [];

    /** @var array<string, string> keyed by Surface->value */
    private array $placeholders = [];

    /** @param array<array-key, string>|null $options */
    private function __construct(
        private readonly string $name,
        private readonly FieldType $type,
        private readonly ?array $options = null,
    ) {
        $this->label = ucfirst(str_replace('_', ' ', $name));
    }

    public static function id(string $name = 'id'): self
    {
        return new self($name, FieldType::Id);
    }

    public static function text(string $name): self
    {
        return new self($name, FieldType::Text);
    }

    /**
     * Keys are stored column values. PHP turns a numeric-looking one into an
     * int, so a status map keyed '200' arrives here keyed 200.
     *
     * @param array<array-key, string> $options
     */
    public static function select(string $name, array $options): self
    {
        return new self($name, FieldType::Select, $options);
    }

    /**
     * The reading half of {@see Input::checkbox()}. The two words are the
     * field's options, so a filterable() boolean gets the same Yes/No select
     * every other option-carrying field gets, with no second declaration.
     */
    public static function boolean(string $name, string $true = 'Yes', string $false = 'No'): self
    {
        return new self($name, FieldType::Boolean, ['0' => $false, '1' => $true]);
    }

    /**
     * A quantity. Renders flush right in tabular figures, so a column of them
     * lines up on the decimal point and can be read down rather than across.
     */
    public static function number(string $name): self
    {
        return new self($name, FieldType::Number);
    }

    /** A calendar day, with no time of day to be in anyone's zone. */
    public static function date(string $name): self
    {
        return new self($name, FieldType::Date);
    }

    public static function datetime(string $name): self
    {
        return new self($name, FieldType::DateTime);
    }

    public function labelled(string $label): self
    {
        $clone = clone $this;
        $clone->label = $label;

        return $clone;
    }

    public function sortable(bool $sortable = true): self
    {
        $clone = clone $this;
        $clone->sortable = $sortable;

        return $clone;
    }

    public function searchable(bool $searchable = true): self
    {
        $clone = clone $this;
        $clone->searchable = $searchable;

        return $clone;
    }

    public function filterable(bool $filterable = true): self
    {
        $clone = clone $this;
        $clone->filterable = $filterable;

        return $clone;
    }

    /** Places after the decimal point. A count wants none; a rate wants two. */
    public function decimals(int $places): self
    {
        $clone = $this->onlyNumber(__FUNCTION__);
        $clone->decimals = max(0, $places);

        return $clone;
    }

    /** Thousands separators. Right for a tally, wrong for a year or a port. */
    public function grouped(bool $grouped = true): self
    {
        $clone = $this->onlyNumber(__FUNCTION__);
        $clone->grouped = $grouped;

        return $clone;
    }

    /**
     * Appended verbatim, so the caller owns the spacing: ' ms' and '%' are
     * both right and only one of them takes a space.
     */
    public function suffix(string $suffix): self
    {
        $clone = $this->onlyNumber(__FUNCTION__);
        $clone->suffix = $suffix;

        return $clone;
    }

    /**
     * How long ago, rather than when. The exact instant stays in a title
     * attribute, because "2 months ago" is the answer to a different question
     * than the one somebody reading an audit trail eventually asks.
     *
     * Only where a screen supplies the reader's clock: an export carries the
     * stored instant, since a file read next week must not say "an hour ago"
     * about the moment it was written.
     */
    public function relative(bool $relative = true): self
    {
        if ($this->type !== FieldType::DateTime) {
            throw new LogicException(sprintf(
                'Admin field "%s" is a %s; only a datetime can be shown as a relative time.',
                $this->name,
                $this->type->value,
            ));
        }

        $clone = clone $this;
        $clone->relative = $relative;

        return $clone;
    }

    /**
     * Shorten the value for display, with the whole of it in a title
     * attribute. A decoration and not a format(): the stored value does reach
     * the output, so the field may stay searchable().
     *
     * The list alone unless surfaces are named, because a row screen and an
     * export are the two places somebody went looking for the whole thing.
     */
    public function truncate(int $length, Surface ...$on): self
    {
        $clone = clone $this;

        foreach ($this->surfacesFor($on === [] ? [Surface::List] : $on) as $surface) {
            $clone->truncations[$surface->value] = max(1, $length);
        }

        return $clone;
    }

    public function onlyOn(Surface ...$surfaces): self
    {
        $clone = clone $this;
        $clone->surfaces = array_values($surfaces);

        return $clone;
    }

    public function hiddenOn(Surface ...$surfaces): self
    {
        $clone = clone $this;
        $clone->surfaces = array_values(array_filter(
            $this->surfaces,
            static fn (Surface $surface): bool => !in_array($surface, $surfaces, true),
        ));

        return $clone;
    }

    /**
     * Replace the value for display, on every surface unless some are named.
     * Rejected by {@see Definition::compile()} on a searchable() field.
     *
     * @param Closure(mixed, array<string, mixed>): (string|HtmlView) $formatter
     */
    public function format(Closure $formatter, Surface ...$on): self
    {
        return $this->withFormatter($formatter, false, $on);
    }

    /**
     * format() for a formatter that leaves the stored value findable in its
     * output, so the field may stay searchable().
     *
     * @param Closure(mixed, array<string, mixed>): (string|HtmlView) $formatter
     */
    public function decorate(Closure $formatter, Surface ...$on): self
    {
        return $this->withFormatter($formatter, true, $on);
    }

    /** What to show in place of a null or empty value. Not itself searchable. */
    public function emptyAs(string $placeholder, Surface ...$on): self
    {
        $clone = clone $this;

        foreach ($this->surfacesFor($on) as $surface) {
            $clone->placeholders[$surface->value] = $placeholder;
        }

        return $clone;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): FieldType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array<array-key, string>|null */
    public function options(): ?array
    {
        return $this->options;
    }

    public function isSortable(): bool
    {
        return $this->sortable;
    }

    public function isSearchable(): bool
    {
        return $this->searchable;
    }

    public function isFilterable(): bool
    {
        return $this->filterable;
    }

    public function appearsOn(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    /**
     * Whether what this field shows on a surface no longer contains what the
     * column stores, which is what makes searching it a lie. truncate() and
     * relative() do not count: both keep the whole of the stored value in a
     * title attribute, which is exactly what decorate() means.
     */
    public function rewritesValueOn(Surface $surface): bool
    {
        if ($this->type === FieldType::Number && ($this->grouped || $this->decimals > 0 || $this->suffix !== '')) {
            return true;
        }

        return ($this->formatters[$surface->value] ?? null) !== null
            && !$this->formatters[$surface->value]['decorates'];
    }

    /**
     * What this field reads as on one surface.
     *
     * $zone is the reader's, and only a datetime is affected by it. Leaving it
     * out renders the stored instant unchanged, which is what an export wants:
     * a file that goes somewhere else should carry the one timezone everything
     * else in it is already in.
     *
     * @param array<string, mixed> $row
     */
    public function display(
        Surface $surface,
        array $row,
        ?DateTimeZone $zone = null,
        ?DateTimeImmutable $now = null,
    ): string|HtmlView {
        $value = $row[$this->name] ?? null;
        $placeholder = $this->placeholders[$surface->value] ?? null;

        if ($placeholder !== null && ($value === null || $value === '')) {
            return $placeholder;
        }

        $formatter = $this->formatters[$surface->value]['fn'] ?? null;

        if ($formatter !== null) {
            return $this->formatted($formatter($value, $row));
        }

        if ($this->type === FieldType::Boolean) {
            // Ahead of the options lookup below, which would be a miss on the
            // null of a never-written column and on the true a source may
            // compute: what a stored flag means is Flag's to say, not a key's.
            return $this->options[Flag::of($value) ? '1' : '0'] ?? '';
        }

        // The four below are never shortened: a quantity and a date are as
        // long as they are, and cutting either one produces a different value
        // rather than a hint of the same one.
        if ($this->type === FieldType::Number) {
            return $this->quantity($value);
        }

        if ($this->type === FieldType::Date && is_scalar($value)) {
            return $this->day((string) $value);
        }

        if ($this->type === FieldType::DateTime && is_scalar($value)) {
            return $this->instant((string) $value, $zone, $now);
        }

        if ($this->options !== null && is_scalar($value)) {
            return $this->shortened($this->options[(string) $value] ?? (string) $value, $surface);
        }

        return $this->shortened(is_scalar($value) ? (string) $value : '', $surface);
    }

    /**
     * A quantity as declared. A value that is not a number is handed back
     * untouched rather than rounded to zero: a column that turns out not to
     * hold numbers is a declaration to fix, not a cell to fill with 0.00.
     */
    private function quantity(mixed $value): string
    {
        if (!is_numeric($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        return number_format((float) $value, $this->decimals, '.', $this->grouped ? ',' : '') . $this->suffix;
    }

    /**
     * The calendar day of a stored value, in no zone at all. A date column
     * holds a day rather than an instant, and shifting one by a few hours is
     * how a birthday lands on the wrong date for half the world.
     */
    private function day(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Exception) {
            return $value;
        }
    }

    /**
     * A stored instant, as whichever of the two readings the screen supplied
     * the means for: relative needs the reader's clock, a time of day needs
     * their zone, and an export supplies neither and gets the stored value.
     */
    private function instant(string $value, ?DateTimeZone $zone, ?DateTimeImmutable $now): string|HtmlView
    {
        if ($this->relative && $now !== null && $value !== '') {
            try {
                $at = self::stored($value);
            } catch (Exception) {
                return $value;
            }

            $exact = $zone === null ? $at : $at->setTimezone($zone);

            return new HtmlView(sprintf(
                '<time datetime="%s" title="%s">%s</time>',
                $this->escaped($at->format(DateTimeImmutable::ATOM)),
                $this->escaped($exact->format('Y-m-d H:i:s')),
                $this->escaped($this->ago($at, $now)),
            ));
        }

        if ($zone === null) {
            return ctype_digit($value) ? self::stored($value)->format('Y-m-d H:i:s') : $value;
        }

        return $this->inZone($value, $zone);
    }

    /**
     * How long ago in the largest unit that still says something: a log read
     * on the day it was written wants minutes, and one read a year later does
     * not want 525,600 of them. Months and years are approximated, which is
     * the point of a relative time; the exact instant is in the title.
     */
    private function ago(DateTimeImmutable $at, DateTimeImmutable $now): string
    {
        $seconds = $now->getTimestamp() - $at->getTimestamp();
        $ahead = $seconds < 0;
        $seconds = abs($seconds);

        $said = match (true) {
            $seconds < 45 => 'a moment',
            $seconds < 3600 => $this->plural((int) round($seconds / 60), 'minute'),
            $seconds < 86400 => $this->plural((int) round($seconds / 3600), 'hour'),
            $seconds < 2592000 => $this->plural((int) round($seconds / 86400), 'day'),
            $seconds < 31536000 => $this->plural((int) round($seconds / 2592000), 'month'),
            default => $this->plural((int) round($seconds / 31536000), 'year'),
        };

        return $ahead ? "in {$said}" : "{$said} ago";
    }

    private function plural(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count === 1 ? '' : 's');
    }

    /**
     * The value cut to length for one surface, with the whole of it in a
     * title attribute. Nothing is cut when it already fits, so a column of
     * short values carries no markup it did not need.
     */
    private function shortened(string $value, Surface $surface): string|HtmlView
    {
        $length = $this->truncations[$surface->value] ?? null;

        if ($length === null || mb_strlen($value) <= $length) {
            return $value;
        }

        return new HtmlView(sprintf(
            '<span title="%s">%s&hellip;</span>',
            $this->escaped($value),
            $this->escaped(rtrim(mb_substr($value, 0, $length))),
        ));
    }

    /**
     * This class builds the only markup the admin produces outside a
     * template, so it escapes its own: a title attribute holding a stored
     * value is exactly where an unescaped quote would matter.
     */
    private function escaped(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A modifier that only a number has any meaning for. */
    private function onlyNumber(string $method): self
    {
        if ($this->type !== FieldType::Number) {
            throw new LogicException(sprintf(
                'Admin field "%s" is a %s; %s() is a number\'s.',
                $this->name,
                $this->type->value,
                $method,
            ));
        }

        return clone $this;
    }

    /**
     * A stored instant as a time of day where the reader is.
     *
     * Read as UTC because that is what the row holds, and handed back untouched
     * when it cannot be read at all: a column that turns out not to be a
     * datetime is a declaration to fix, not a cell to blank out mid-page.
     */
    private function inZone(string $value, DateTimeZone $zone): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return self::stored($value)
                ->setTimezone($zone)
                ->format('Y-m-d H:i:s');
        } catch (Exception) {
            return $value;
        }
    }

    /** A column of unix seconds is as much an instant as a DATETIME in UTC. */
    private static function stored(string $value): DateTimeImmutable
    {
        return ctype_digit($value)
            ? new DateTimeImmutable('@' . $value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** @param list<Surface> $on */
    private function withFormatter(Closure $formatter, bool $decorates, array $on): self
    {
        $clone = clone $this;

        foreach ($this->surfacesFor($on) as $surface) {
            $clone->formatters[$surface->value] = ['fn' => $formatter, 'decorates' => $decorates];
        }

        return $clone;
    }

    /**
     * @param list<Surface> $on
     * @return list<Surface>
     */
    private function surfacesFor(array $on): array
    {
        return $on === [] ? Surface::cases() : array_values($on);
    }

    private function formatted(mixed $formatted): string|HtmlView
    {
        if (is_string($formatted) || $formatted instanceof HtmlView) {
            return $formatted;
        }

        throw new LogicException(sprintf(
            'The formatter for admin field "%s" returned %s; it must return a string or %s.',
            $this->name,
            get_debug_type($formatted),
            HtmlView::class,
        ));
    }
}
