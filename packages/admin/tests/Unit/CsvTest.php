<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Csv;
use Hydra\Admin\Field;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The file an export hands over: the shape RFC 4180 asks for, and the two
 * things a spreadsheet does with a file that the writer has to answer for —
 * evaluating a cell that looks like a formula, and reading UTF-8 as a code page.
 */
#[CoversClass(Csv::class)]
final class CsvTest extends TestCase
{
    public function test_the_first_line_is_the_labels_and_the_file_opens_as_utf8(): void
    {
        $csv = Csv::render(
            [Field::id(), Field::text('username')->labelled('Name')],
            [['id' => 1, 'username' => 'ada']],
        );

        $this->assertStringStartsWith(Csv::BOM . '"Id","Name"' . Csv::EOL, $csv);
        $this->assertStringEndsWith('"1","ada"' . Csv::EOL, $csv);
    }

    public function test_every_row_the_extraction_yields_is_written(): void
    {
        $csv = Csv::render(
            [Field::text('username')],
            [['username' => 'ada'], ['username' => 'grace'], ['username' => 'alan']],
        );

        // Heading plus three rows, each closed by the record separator.
        $this->assertSame(4, substr_count($csv, Csv::EOL));
    }

    public function test_it_renders_the_export_surface_and_not_the_table(): void
    {
        $field = Field::text('username')
            ->format(static fn (mixed $value): string => 'cell: ' . (string) $value, Surface::List)
            ->format(static fn (mixed $value): string => 'file: ' . (string) $value, Surface::Export);

        $this->assertStringContainsString('"file: ada"', Csv::render([$field], [['username' => 'ada']]));
    }

    /** @return array<string, array{string, string}> */
    public static function cells(): array
    {
        return [
            'a separator inside a value cannot end the cell' => ['a,b', '"a,b"'],
            'a quote is doubled, not escaped'                => ['say "hi"', '"say ""hi"""'],
            'a newline stays inside the quotes'              => ["two\nlines", "\"two\nlines\""],
            'a lone carriage return is normalised'           => ["two\rlines", "\"two\nlines\""],
            'a windows line ending is normalised'            => ["two\r\nlines", "\"two\nlines\""],
            'an empty value is still a quoted cell'          => ['', '""'],
        ];
    }

    #[DataProvider('cells')]
    public function test_a_value_survives_the_round_trip_into_a_cell(string $value, string $expected): void
    {
        $this->assertSame($expected, Csv::cell($value));
    }

    /** @return array<string, array{string, string}> */
    public static function formulas(): array
    {
        return [
            'an equals sign opens a formula'    => ['=1+1', '"\'=1+1"'],
            'so does a plus'                    => ['+1+1', '"\'+1+1"'],
            'but a signed number is a number'   => ['+1', '"+1"'],
            'so does an at sign'                => ['@SUM(A1)', '"\'@SUM(A1)"'],
            'so does a tab'                     => ["\t=1+1", "\"'\t=1+1\""],
            'a minus in front of an expression' => ['-1+1', '"\'-1+1"'],
            'a negative number is a number'     => ['-5', '"-5"'],
            'and so is a negative decimal'      => ['-5.25', '"-5.25"'],
            'an ordinary value is untouched'    => ['ada', '"ada"'],
        ];
    }

    /**
     * A spreadsheet evaluates a cell that opens with a formula character, and
     * the rows an admin exports are the ones strangers wrote. Quoting is no
     * defence: the characters are read inside the quotes too.
     */
    #[DataProvider('formulas')]
    public function test_a_cell_a_spreadsheet_would_run_is_marked_as_text(string $value, string $expected): void
    {
        $this->assertSame($expected, Csv::cell($value));
    }

    /**
     * The same field declaration renders a table cell, and a table cell is
     * allowed to be markup. A column of a spreadsheet is not, so what reaches
     * the file is the text the markup was wrapped around.
     */
    public function test_markup_from_a_formatter_arrives_as_the_text_inside_it(): void
    {
        $badge = new HtmlView("<span class=\"badge\">\n  Ada &amp; Grace\n</span>");

        $this->assertSame('"Ada & Grace"', Csv::cell($badge));
    }

    public function test_a_line_is_the_cells_joined_and_closed(): void
    {
        $this->assertSame('"a","b"' . Csv::EOL, Csv::line(['a', 'b']));
    }
}
