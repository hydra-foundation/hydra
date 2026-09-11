<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ListScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\Admin\Tests\Support\ArraySource;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    public function test_it_titles_a_module_from_its_slug(): void
    {
        $this->assertSame('Audit log', Definition::make('audit-log')->screens(new ListScreen)->compile()->title);
    }

    public function test_a_source_and_fields_imply_a_list_screen(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::text('username'))
            ->compile();

        $this->assertCount(1, $blueprint->screens);
        $this->assertSame('list', $blueprint->screens[0]->name());
    }

    public function test_an_explicit_list_screen_is_not_duplicated(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::text('username'))
            ->screens(new ListScreen('App\Authorization\AccessAdmin'))
            ->compile();

        $this->assertCount(1, $blueprint->screens);
        $this->assertSame('App\Authorization\AccessAdmin', $blueprint->screens[0]->ability());
    }

    public function test_a_module_with_no_screens_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        Definition::make('users')->compile();
    }

    public function test_a_service_id_source_is_kept_unresolved(): void
    {
        $blueprint = Definition::make('users')
            ->source(ArraySource::class)
            ->fields(Field::text('username'))
            ->compile();

        $this->assertSame(ArraySource::class, $blueprint->source);
    }

    public function test_a_searchable_field_may_not_also_rewrite_its_value(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('both searchable() and format()');

        Definition::make('activity')
            ->source(new ArraySource)
            ->fields(
                Field::text('username')->searchable()
                    ->format(static fn (mixed $value): string => (string) ($value ?? 'guest')),
            )
            ->compile();
    }

    public function test_a_searchable_field_may_decorate_what_it_shows(): void
    {
        $blueprint = Definition::make('activity')
            ->source(new ArraySource)
            ->fields(
                Field::text('path')->searchable()
                    ->decorate(static fn (mixed $value, array $row): string => $value . '?' . $row['query']),
                Field::text('username')->searchable()->emptyAs('guest'),
            )
            ->compile();

        $this->assertCount(2, $blueprint->searchable());
    }

    public function test_a_formatter_off_the_list_does_not_trip_the_search_rule(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(
                Field::text('username')->searchable()
                    ->format(static fn (mixed $value): string => strtoupper((string) $value), Surface::Show),
            )
            ->compile();

        $this->assertCount(1, $blueprint->searchable());
    }

    public function test_the_blueprint_projects_fields_onto_surfaces(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(
                Field::text('username')->sortable()->searchable(),
                Field::select('role', ['admin' => 'Admin'])->filterable(),
                Field::text('password')->onlyOn(Surface::Show),
            )
            ->compile();

        $this->assertSame(['username', 'role'], array_map(
            static fn (Field $field): string => $field->name(),
            $blueprint->fieldsOn(Surface::List),
        ));
        $this->assertCount(1, $blueprint->sortable());
        $this->assertCount(1, $blueprint->searchable());
        $this->assertCount(1, $blueprint->filterable());
    }
    public function test_a_row_screen_needs_a_field_that_names_a_row(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Field::id() to name one with');

        Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::text('username'))
            ->screens(ShowScreen::make())
            ->compile();
    }

    public function test_a_module_with_no_row_screen_needs_no_field_that_names_one(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::text('username'))
            ->screens(FormScreen::create()->inputs(Input::text('username')))
            ->compile();

        $this->assertNull($blueprint->identifier());
        $this->assertSame('new', $blueprint->screen('create')?->path());
    }

}
