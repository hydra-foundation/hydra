<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\ViewModels\ShowViewModel;
use PHPUnit\Framework\TestCase;

/**
 * What a show template reads: the fields the table had no room for, rendered
 * through the Show surface, and the way on to the edit screen when there is one.
 */
final class ShowViewModelTest extends TestCase
{
    private const ROW = ['id' => 42, 'username' => 'ada', 'user_agent' => 'Mozilla/5.0', 'role' => 'admin'];

    public function test_it_shows_the_fields_the_table_had_no_room_for(): void
    {
        $vm = $this->viewModel();

        $this->assertSame(
            ['id', 'username', 'user_agent', 'role'],
            array_map(static fn (Field $field): string => $field->name(), $vm->fields()),
        );
        $this->assertNotContains(
            'user_agent',
            array_map(static fn (Field $field): string => $field->name(), $vm->blueprint->fieldsOn(Surface::List)),
        );
    }

    public function test_it_renders_values_through_the_show_surface(): void
    {
        $vm = $this->viewModel();
        [, $username, $agent, $role] = $vm->fields();

        $this->assertSame('ada', $vm->value($username));
        $this->assertSame('Mozilla/5.0', $vm->value($agent));
        $this->assertSame('Administrator', $vm->value($role));
    }

    public function test_a_missing_column_shows_as_empty_rather_than_failing(): void
    {
        $vm = new ShowViewModel($this->blueprint(), '42', '/admin', ['id' => 42]);
        [, $username] = $vm->fields();

        $this->assertSame('', $vm->value($username));
    }

    public function test_it_offers_the_way_on_to_the_edit_screen(): void
    {
        $this->assertSame('/admin/users/42/edit', $this->viewModel()->editUrl());
        $this->assertSame('/admin/users', $this->viewModel()->listUrl());
    }

    public function test_an_id_needing_encoding_survives_the_url(): void
    {
        $this->assertSame('/admin/users/a%2Fb/edit', $this->viewModel('a/b')->editUrl());
    }

    public function test_there_is_no_edit_link_when_the_module_declares_no_form(): void
    {
        $blueprint = Definition::make('activity')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(ShowScreen::make())
            ->compile();

        $this->assertNull((new ShowViewModel($blueprint, '42', '/admin', self::ROW))->editUrl());
    }

    private function viewModel(string $id = '42'): ShowViewModel
    {
        return new ShowViewModel($this->blueprint(), $id, '/admin', self::ROW);
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(
                Field::id(),
                Field::text('username'),
                Field::text('user_agent')->labelled('Agent')->hiddenOn(Surface::List),
                Field::select('role', ['admin' => 'Administrator', 'user' => 'User']),
            )
            ->screens(
                ShowScreen::make(),
                FormScreen::edit()->inputs(Input::text('username')),
            )
            ->compile();
    }
}
