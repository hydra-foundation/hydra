<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Field;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;
use LogicException;
use PHPUnit\Framework\TestCase;

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
}
