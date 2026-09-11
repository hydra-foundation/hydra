<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ShowScreenTest extends TestCase
{
    public function test_it_reads_one_row_through_the_admin_controller(): void
    {
        $screen = ShowScreen::make();

        $this->assertSame('show', $screen->name());
        $this->assertSame('GET', $screen->method());
        $this->assertSame('{id}', $screen->path());
        $this->assertSame([AdminController::class, 'show'], $screen->handler());
        $this->assertNull($screen->heading());
    }

    public function test_it_compiles_to_a_route_that_carries_an_id(): void
    {
        $routes = (new ModuleScanner)->scan([$this->blueprint()], '/admin');

        $this->assertContains('/admin/users/{id}', array_column($routes, 'path'));
        $this->assertContains('users.show', array_column($routes, 'name'));
    }

    public function test_it_is_read_only_so_it_compiles_to_no_post(): void
    {
        $routes = (new ModuleScanner)->scan([$this->blueprint()], '/admin');
        $posts = array_filter($routes, static fn (array $route): bool => $route['method'] === 'POST');

        $this->assertSame(['/admin/users/{id}/edit'], array_column($posts, 'path'));
    }

    public function test_a_literal_sibling_still_wins_over_the_id_it_looks_like(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(ShowScreen::make(), PageScreen::make('new', 'admin/new')->at('new'))
            ->compile();

        $registry = new ModuleRegistry(new ArrayContainer([]), []);

        $this->assertSame('new', $registry->screenAt($blueprint, '/admin/users/new', 'GET')?->name());
        $this->assertSame('show', $registry->screenAt($blueprint, '/admin/users/42', 'GET')?->name());
    }

    public function test_a_screen_ability_overrides_the_modules(): void
    {
        $blueprint = Definition::make('users')
            ->ability('AccessAdmin')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(ShowScreen::make()->requires('ViewUsers')->title('User'))
            ->compile();

        $this->assertSame('ViewUsers', $blueprint->screen('show')?->ability());
        $this->assertSame('User', $blueprint->screen('show')?->heading());
    }

    public function test_a_path_that_names_no_row_is_rejected_where_it_is_declared(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must carry {id}');

        ShowScreen::make('detail');
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id(), Field::text('username'))
            ->screens(
                ShowScreen::make(),
                FormScreen::edit()->inputs(Input::text('username')),
            )
            ->compile();
    }
}
