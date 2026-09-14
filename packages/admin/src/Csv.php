<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\View\HtmlView;

/**
 * Fields and rows as a CSV file, RFC 4180 shaped and safe to open.
 *
 * "Safe to open" is doing real work here. A spreadsheet is not an inert
 * viewer: it evaluates a cell that begins with a formula character, and the
 * rows an admin exports are usually the ones strangers wrote. Guarding that is
 * this class's job and not the caller's, because a caller only forgets once.
 */
final class Csv
{
    /**
     * Excel reads a file with no byte-order mark as the machine's legacy code
     * page, so a UTF-8 export of anything but ASCII opens as mojibake. Every
     * other reader tolerates the mark; Excel is the one that needs it.
     */
    public const BOM = "\xEF\xBB\xBF";

    /** RFC 4180's record separator, which is also what Excel writes. */
    public const EOL = "\r\n";

    /**
     * The characters a spreadsheet reads as "this cell is code". A tab or a
     * carriage return counts because the leading one is stripped before the
     * rest of the cell is looked at.
     */
    private const FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * What defuses one. An apostrophe is the spreadsheet's own "the rest of
     * this cell is text", so it is read as the marker rather than as content.
     */
    private const GUARD = "'";

    /**
     * @param list<Field> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public static function render(array $columns, iterable $rows, Surface $surface = Surface::Export): string
    {
        $csv = self::BOM . self::line(array_map(
            static fn (Field $field): string => $field->label(),
            $columns,
        ));

        foreach ($rows as $row) {
            $csv .= self::line(array_map(
                static fn (Field $field): string|HtmlView => $field->display($surface, $row),
                $columns,
            ));
        }

        return $csv;
    }

    /** @param list<string|HtmlView> $values */
    public static function line(array $values): string
    {
        return implode(',', array_map(self::cell(...), $values)) . self::EOL;
    }

    /**
     * Every cell is quoted, including the ones that would not have needed it.
     * RFC 4180 allows that everywhere, and one rule that always holds is worth
     * more here than a shorter file: the alternative is a predicate deciding
     * per cell which of the separator, the quote and the newline it contains,
     * and that predicate is where CSV writers go wrong.
     */
    public static function cell(string|HtmlView $value): string
    {
        return '"' . str_replace('"', '""', self::guarded(self::text($value))) . '"';
    }

    /**
     * A cell a spreadsheet will read as text.
     *
     * A number is left as written even when it opens with a sign, because
     * "-1" is a number a reader expects to be able to add up and "-1+1" is not
     * one. Quoting is no defence on its own: the formula characters are read
     * inside the quotes too.
     */
    private static function guarded(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        return in_array($value[0], self::FORMULA, true) ? self::GUARD . $value : $value;
    }

    /**
     * The text of a value.
     *
     * A formatter may return markup, because the same declaration also renders
     * a table cell, and a cell cannot hold markup: the text inside it is what a
     * column of a spreadsheet is for. Entities are decoded on the way through,
     * since they were escaping for HTML and there is none here; the whitespace
     * a template left between tags collapses with them.
     *
     * A plain string is left as the module stored it, bar the line endings: a
     * lone CR inside a quoted field is legal and reads as a new record to
     * enough software to be worth normalising away.
     */
    private static function text(string|HtmlView $value): string
    {
        if (!$value instanceof HtmlView) {
            return str_replace(["\r\n", "\r"], "\n", $value);
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
