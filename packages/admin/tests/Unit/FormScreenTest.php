<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Validation\Rules\MinLength;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The create and edit screens: the routes each compiles to, that a literal path
 * is registered ahead of the {id} that would otherwise swallow it, and that the
 * two forms can differ on the same column.
 */
final class FormScreenTest extends TestCase
{
    public function test_it_sits_at_a_parameterised_path_under_the_module(): void
    {
        $screen = FormScreen::edit()->inputs(Input::text('username'));

        $this->assertSame('edit', $screen->name());
        $this->assertSame('GET', $screen->method());
        $this->assertSame('{id}/edit', $screen->path());
        $this->assertSame([AdminController::class, 'edit'], $screen->handler());
        $this->assertSame([AdminController::class, 'update'], $screen->submitHandler());
    }

    public function test_it_compiles_to_a_get_and_a_post_at_the_same_url(): void
    {
        $routes = (new ModuleScanner)->scan([$this->blueprint()], '/admin');

        $this->assertSame(
            [['GET', '/admin/users'], ['GET', '/admin/users/{id}/edit'], ['POST', '/admin/users/{id}/edit']],
            array_map(static fn (array $route): array => [$route['method'], $route['path']], $routes),
        );
        $this->assertSame('users.edit', $routes[1]['name']);
        $this->assertSame('users.edit.submit', $routes[2]['name']);
        $this->assertSame([AdminController::class, 'update'], $routes[2]['handler']);
    }

    public function test_a_create_screen_names_no_row_and_stores_instead_of_updating(): void
    {
        $screen = FormScreen::create()->inputs(Input::text('username'));

        $this->assertSame('create', $screen->name());
        $this->assertSame('new', $screen->path());
        $this->assertSame([AdminController::class, 'create'], $screen->handler());
        $this->assertSame([AdminController::class, 'store'], $screen->submitHandler());
    }

    public function test_a_literal_create_path_is_registered_ahead_of_the_id_that_would_swallow_it(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(
                ShowScreen::make(),
                FormScreen::edit()->inputs(Input::text('username')),
                FormScreen::create()->inputs(Input::text('username')),
            )
            ->compile();

        $paths = array_column((new ModuleScanner)->scan([$blueprint], '/admin'), 'path');

        $this->assertLessThan(
            array_search('/admin/users/{id}', $paths, true),
            array_search('/admin/users/new', $paths, true),
        );
    }

    public function test_the_two_forms_can_differ_on_the_same_column(): void
    {
        $create = FormScreen::create()->inputs(Input::password('password')->required());
        $edit = FormScreen::edit()->inputs(Input::password('password'));

        $this->assertCount(1, $create->rulesFor(['password' => ''])['password']);
        $this->assertSame([], $edit->rulesFor(['password' => ''])['password']);
    }

    public function test_the_rule_set_is_built_against_the_submission(): void
    {
        $screen = FormScreen::edit()->inputs(
            Input::text('username')->required(),
            Input::password('password')->rules(new MinLength(8)),
        );

        $blank = $screen->rulesFor(['username' => 'ada', 'password' => '']);
        $changing = $screen->rulesFor(['username' => 'ada', 'password' => 'short']);

        $this->assertSame([], $blank['password']);
        $this->assertCount(1, $changing['password']);
        $this->assertCount(1, $blank['username']);
    }

    public function test_an_edit_path_that_names_no_row_is_rejected_where_it_is_declared(): void
    {
        // create() is exempt: there is no row yet to name.
        $this->assertSame('form', FormScreen::create('form')->path());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must carry {id}');

        FormScreen::edit('form');
    }

    public function test_a_form_screen_with_no_inputs_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('form screen "edit" with no inputs');

        Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id(), Field::text('username'))
            ->screens(FormScreen::edit())
            ->compile();
    }

    private function blueprint(): \Hydra\Admin\Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id(), Field::text('username'))
            ->screens(FormScreen::edit()->inputs(Input::text('username')->required()))
            ->compile();
    }
}
