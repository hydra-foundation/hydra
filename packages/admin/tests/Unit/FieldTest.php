<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Field;
use Hydra\Admin\FieldType;
use Hydra\Admin\Flag;
use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A field declaration and how it renders: labels, which surfaces it appears on,
 * and the formatter, including the cases where a bad one names itself rather
 * than failing somewhere further down the render.
 */
#[CoversClass(FieldType::class)]
#[CoversClass(Field::class)]
#[CoversClass(Flag::class)]
final class FieldTest extends TestCase
{
    public function test_it_humanizes_the_name_into_a_default_label(): void
    {
        $this->assertSame('Created at', Field::datetime('created_at')->label());
    }

    public function test_fluent_calls_do_not_mutate_the_original(): void
    {
        $field = Field::text('username');

        $this->assertTrue($field->sortable()->isSortable());
        $this->assertFalse($field->isSortable());
    }

    public function test_a_field_appears_on_every_surface_by_default(): void
    {
        $field = Field::text('username');

        $this->assertTrue($field->appearsOn(Surface::List));
        $this->assertTrue($field->appearsOn(Surface::Show));
        $this->assertTrue($field->appearsOn(Surface::Show));
    }

    public function test_only_on_and_hidden_on_narrow_the_surfaces(): void
    {
        $only = Field::text('password')->onlyOn(Surface::Show);
        $hidden = Field::text('username')->hiddenOn(Surface::Show);

        $this->assertTrue($only->appearsOn(Surface::Show));
        $this->assertFalse($only->appearsOn(Surface::List));
        $this->assertFalse($hidden->appearsOn(Surface::Show));
        $this->assertTrue($hidden->appearsOn(Surface::List));
    }

    public function test_a_select_displays_the_option_label(): void
    {
        $field = Field::select('role', ['admin' => 'Administrator']);

        $this->assertSame('Administrator', $field->display(Surface::List, ['role' => 'admin']));
        $this->assertSame('ghost', $field->display(Surface::List, ['role' => 'ghost']));
    }

    public function test_a_formatter_wins_over_the_raw_value(): void
    {
        $field = Field::text('username')->format(static fn (mixed $value): string => strtoupper((string) $value));

        $this->assertSame('ADA', $field->display(Surface::List, ['username' => 'ada']));
    }

    public function test_a_missing_value_displays_as_empty(): void
    {
        $this->assertSame('', Field::text('username')->display(Surface::List, []));
    }

    public function test_a_formatter_applies_to_every_surface_unless_some_are_named(): void
    {
        $everywhere = Field::text('username')->format(static fn (): string => 'x');
        $listOnly = Field::text('username')->format(static fn (): string => 'x', Surface::List);

        $this->assertSame('x', $everywhere->display(Surface::Show, ['username' => 'ada']));
        $this->assertSame('ada', $listOnly->display(Surface::Show, ['username' => 'ada']));
        $this->assertSame('x', $listOnly->display(Surface::List, ['username' => 'ada']));
    }

    public function test_a_formatter_may_return_markup_the_template_will_not_escape(): void
    {
        $field = Field::text('username')->format(
            static fn (mixed $value): HtmlView => new HtmlView('<b>' . $value . '</b>'),
        );

        $rendered = $field->display(Surface::List, ['username' => 'ada']);

        $this->assertInstanceOf(HtmlView::class, $rendered);
        $this->assertSame('<b>ada</b>', (string) $rendered);
    }

    public function test_a_formatter_returning_the_wrong_type_names_the_field(): void
    {
        // The wrong return type is the subject here: the guard exists because
        // a formatter can only be checked once it has run.
        /** @phpstan-ignore argument.type */
        $field = Field::text('duration_ms')->format(static fn (mixed $value): int => (int) $value * 2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('admin field "duration_ms" returned int');

        $field->display(Surface::List, ['duration_ms' => 21]);
    }

    public function test_empty_as_stands_in_for_a_null_or_blank_value(): void
    {
        $field = Field::text('username')->emptyAs('guest');

        $this->assertSame('guest', $field->display(Surface::List, ['username' => null]));
        $this->assertSame('guest', $field->display(Surface::List, ['username' => '']));
        $this->assertSame('guest', $field->display(Surface::List, []));
        $this->assertSame('ada', $field->display(Surface::List, ['username' => 'ada']));
    }

    public function test_only_a_value_replacing_formatter_counts_as_a_rewrite(): void
    {
        $plain = Field::text('path');

        $this->assertFalse($plain->rewritesValueOn(Surface::List));
        $this->assertFalse($plain->decorate(static fn (): string => 'x')->rewritesValueOn(Surface::List));
        $this->assertTrue($plain->format(static fn (): string => 'x')->rewritesValueOn(Surface::List));

        $this->assertFalse(
            $plain->format(static fn (): string => 'x', Surface::Show)->rewritesValueOn(Surface::List),
        );
    }

    /**
     * A declared column is part of the export unless the module says otherwise.
     * The alternative is an export that silently ships fewer columns than the
     * module declared, which nobody notices until the file is open.
     */
    public function test_a_field_is_an_export_column_by_default(): void
    {
        $this->assertTrue(Field::text('username')->appearsOn(Surface::Export));
        $this->assertFalse(Field::text('password')->onlyOn(Surface::Show)->appearsOn(Surface::Export));
        $this->assertFalse(Field::text('secret')->hiddenOn(Surface::Export)->appearsOn(Surface::Export));
    }

    /**
     * A column too wide for a table is the one an export is most wanted for, so
     * hiding it from the list must not hide it from the file.
     */
    public function test_hiding_a_column_from_the_table_leaves_it_in_the_export(): void
    {
        $field = Field::text('user_agent')->hiddenOn(Surface::List);

        $this->assertFalse($field->appearsOn(Surface::List));
        $this->assertTrue($field->appearsOn(Surface::Export));
    }

    public function test_a_boolean_reads_whatever_the_column_holds_as_one_of_two_words(): void
    {
        $field = Field::boolean('active');

        // Every shape a stored flag arrives in: sqlite's int, MariaDB's string
        // through PDO, and the real bool a computed column hands back.
        $this->assertSame('Yes', $field->display(Surface::List, ['active' => 1]));
        $this->assertSame('Yes', $field->display(Surface::List, ['active' => '1']));
        $this->assertSame('Yes', $field->display(Surface::List, ['active' => true]));
        $this->assertSame('No', $field->display(Surface::List, ['active' => 0]));
        $this->assertSame('No', $field->display(Surface::List, ['active' => null]));
        // A column never written is not a blank cell; it is the false it reads as.
        $this->assertSame('No', $field->display(Surface::List, []));
    }

    public function test_a_boolean_says_its_own_two_words(): void
    {
        $field = Field::boolean('active', 'Enabled', 'Suspended');

        $this->assertSame('Enabled', $field->display(Surface::List, ['active' => 1]));
        $this->assertSame('Suspended', $field->display(Surface::List, ['active' => 0]));
    }

    public function test_a_booleans_words_are_its_options_so_it_can_be_filtered_on(): void
    {
        // The filter select reads options() like any other field's, which is
        // why the two words are stored there rather than in a pair of their own.
        $this->assertSame(['0' => 'No', '1' => 'Yes'], Field::boolean('active')->options());
    }

    public function test_a_number_is_a_quantity_and_says_how_precise_it_is(): void
    {
        $row = ['views' => '1234.567'];

        $this->assertSame('1235', Field::number('views')->display(Surface::List, $row));
        $this->assertSame('1234.57', Field::number('views')->decimals(2)->display(Surface::List, $row));
        $this->assertSame('1,235', Field::number('views')->grouped()->display(Surface::List, $row));
        $this->assertSame('1,234.57', Field::number('views')->grouped()->decimals(2)->display(Surface::List, $row));
    }

    public function test_a_numbers_suffix_is_appended_verbatim(): void
    {
        // The caller owns the spacing, because ' ms' and '%' are both right
        // and only one of them takes a space.
        $this->assertSame('42 ms', Field::number('duration_ms')->suffix(' ms')->display(Surface::List, ['duration_ms' => 42]));
        $this->assertSame('99%', Field::number('uptime')->suffix('%')->display(Surface::List, ['uptime' => 99]));
    }

    public function test_a_value_that_is_not_a_number_is_handed_back_rather_than_rounded(): void
    {
        // A column that turns out not to hold numbers is a declaration to fix.
        // Showing 0.00 for it would hide that, and in a column of money it
        // would hide it as a plausible figure.
        $field = Field::number('views')->decimals(2);

        $this->assertSame('n/a', $field->display(Surface::List, ['views' => 'n/a']));
        $this->assertSame('', $field->display(Surface::List, ['views' => null]));
    }

    public function test_a_number_that_reshapes_its_value_may_not_be_searched(): void
    {
        // Same rule format() is held to, for the same reason: "1,234" is not
        // what anyone types, and not what the source would match.
        $this->assertFalse(Field::number('views')->rewritesValueOn(Surface::List));
        $this->assertTrue(Field::number('views')->grouped()->rewritesValueOn(Surface::List));
        $this->assertTrue(Field::number('views')->decimals(2)->rewritesValueOn(Surface::List));
        $this->assertTrue(Field::number('ms')->suffix(' ms')->rewritesValueOn(Surface::List));
    }

    public function test_only_a_number_takes_a_numbers_modifiers(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Admin field \"username\" is a text; decimals() is a number's.");

        Field::text('username')->decimals(2);
    }

    public function test_a_date_is_a_day_and_is_in_nobody_s_zone(): void
    {
        // Shifting a stored day by a few hours is how a birthday lands on the
        // wrong date for half the world.
        $field = Field::date('born_on');
        $tokyo = new DateTimeZone('Asia/Tokyo');

        $this->assertSame('1990-03-14', $field->display(Surface::List, ['born_on' => '1990-03-14'], $tokyo));
        $this->assertSame('1990-03-14', $field->display(Surface::List, ['born_on' => '1990-03-14 23:30:00'], $tokyo));
        $this->assertSame('', $field->display(Surface::List, ['born_on' => '']));
        $this->assertSame('not a date', $field->display(Surface::List, ['born_on' => 'not a date']));
    }

    public function test_a_relative_time_says_how_long_ago_in_the_unit_that_still_means_something(): void
    {
        $field = Field::datetime('created_at')->relative();
        $now = new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame('a moment ago', $this->text($field, '2026-09-21 11:59:40', $now));
        $this->assertSame('5 minutes ago', $this->text($field, '2026-09-21 11:55:00', $now));
        $this->assertSame('1 hour ago', $this->text($field, '2026-09-21 11:00:00', $now));
        $this->assertSame('3 days ago', $this->text($field, '2026-09-18 12:00:00', $now));
        $this->assertSame('2 months ago', $this->text($field, '2026-07-23 12:00:00', $now));
        $this->assertSame('2 years ago', $this->text($field, '2024-09-21 12:00:00', $now));
    }

    public function test_a_relative_time_can_be_ahead_of_the_reader(): void
    {
        $field = Field::datetime('runs_at')->relative();
        $now = new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame('in 2 hours', $this->text($field, '2026-09-21 14:00:00', $now));
    }

    public function test_a_relative_time_keeps_the_exact_instant_in_reach(): void
    {
        $rendered = Field::datetime('created_at')->relative()->display(
            Surface::List,
            ['created_at' => '2026-09-21 11:00:00'],
            new DateTimeZone('Asia/Tokyo'),
            new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC')),
        );

        $this->assertInstanceOf(HtmlView::class, $rendered);
        // Titled in the reader's zone, the way a non-relative datetime is
        // shown, so hovering answers the question the column stopped showing.
        $this->assertStringContainsString('title="2026-09-21 20:00:00"', (string) $rendered);
        $this->assertStringContainsString('<time datetime="2026-09-21T11:00:00+00:00"', (string) $rendered);
    }

    public function test_an_export_gets_the_stored_instant_and_not_a_relative_one(): void
    {
        // A file read next week must not say "an hour ago" about the moment it
        // was written. No clock, no relative reading.
        $this->assertSame(
            '2026-09-21 11:00:00',
            Field::datetime('created_at')->relative()->display(Surface::Export, ['created_at' => '2026-09-21 11:00:00']),
        );
    }

    public function test_a_column_of_unix_seconds_reads_as_the_instant_it_counts_to(): void
    {
        $field = Field::datetime('failed_at');
        $stored = (string) (new DateTimeImmutable('2026-09-21 11:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $now = new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC'));

        $this->assertSame('2026-09-21 11:00:00', $field->display(Surface::Export, ['failed_at' => $stored]));
        $this->assertSame('2026-09-21 20:00:00', $field->display(Surface::List, ['failed_at' => $stored], new DateTimeZone('Asia/Tokyo')));
        $this->assertSame('1 hour ago', $this->text($field->relative(), $stored, $now));
    }

    public function test_only_a_datetime_can_be_relative(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only a datetime can be shown as a relative time');

        Field::text('username')->relative();
    }

    public function test_truncate_shortens_the_cell_and_keeps_the_whole_value(): void
    {
        $agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36';
        $rendered = Field::text('user_agent')->truncate(20)->display(Surface::List, ['user_agent' => $agent]);

        $this->assertInstanceOf(HtmlView::class, $rendered);
        $this->assertStringContainsString('Mozilla/5.0 (X11; Li&hellip;', (string) $rendered);
        $this->assertStringContainsString('title="' . htmlspecialchars($agent, ENT_QUOTES) . '"', (string) $rendered);
    }

    public function test_truncate_leaves_a_value_that_already_fits_alone(): void
    {
        // No markup a short value did not need, so a column of them stays a
        // column of plain strings.
        $this->assertSame('ada', Field::text('username')->truncate(20)->display(Surface::List, ['username' => 'ada']));
    }

    public function test_truncate_is_the_lists_business_and_not_an_exports(): void
    {
        $agent = str_repeat('a', 50);
        $field = Field::text('user_agent')->truncate(20);

        $this->assertInstanceOf(HtmlView::class, $field->display(Surface::List, ['user_agent' => $agent]));
        $this->assertSame($agent, $field->display(Surface::Show, ['user_agent' => $agent]));
        $this->assertSame($agent, $field->display(Surface::Export, ['user_agent' => $agent]));
    }

    public function test_a_truncated_field_may_still_be_searched(): void
    {
        // A decoration and not a format(): the whole stored value reaches the
        // output, in the title attribute, so the search box is not lying.
        $this->assertFalse(Field::text('user_agent')->truncate(20)->rewritesValueOn(Surface::List));
    }

    public function test_an_escaped_value_cannot_break_out_of_the_title_it_is_put_in(): void
    {
        // The only markup the admin builds outside a template, so it is the
        // only place the escaping is this class's own job.
        $rendered = (string) Field::text('note')
            ->truncate(5)
            ->display(Surface::List, ['note' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $rendered);
    }

    private function text(Field $field, string $stored, DateTimeImmutable $now): string
    {
        $rendered = (string) $field->display(Surface::List, [$field->name() => $stored], null, $now);

        return strip_tags($rendered);
    }
}
