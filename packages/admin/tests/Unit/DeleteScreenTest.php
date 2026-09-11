<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DeleteScreenTest extends TestCase
{
    public function test_it_is_a_post_and_nothing_else(): void
    {
        $screen = DeleteScreen::make();

        $this->assertSame('delete', $screen->name());
        $this->assertSame('POST', $screen->method());
        $this->assertSame('{id}/delete', $screen->path());
        $this->assertSame([AdminController::class, 'destroy'], $screen->handler());
    }

    public function test_it_compiles_to_a_single_route_with_no_get_beside_it(): void
    {
        $routes = (new ModuleScanner)->scan([$this->blueprint()], '/admin');
        $mine = array_values(array_filter(
            $routes,
            static fn (array $route): bool => str_ends_with($route['path'], '/delete'),
        ));

        $this->assertCount(1, $mine);
        $this->assertSame('POST', $mine[0]['method']);
        $this->assertSame('users.delete', $mine[0]['name']);
    }

    public function test_it_carries_a_prompt_the_visitor_is_asked(): void
    {
        $this->assertStringContainsString('cannot be undone', DeleteScreen::make()->prompt());
        $this->assertSame('Sure?', DeleteScreen::make()->confirm('Sure?')->prompt());
    }

    public function test_a_screen_ability_overrides_the_modules(): void
    {
        $blueprint = Definition::make('users')
            ->ability('AccessAdmin')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(DeleteScreen::make()->requires('DeleteUsers'))
            ->compile();

        $this->assertSame('DeleteUsers', $blueprint->screen('delete')?->ability());
    }

    public function test_the_path_resolves_back_to_the_screen(): void
    {
        $registry = new ModuleRegistry(new ArrayContainer([]), []);

        $this->assertSame(
            'delete',
            $registry->screenAt($this->blueprint(), '/admin/users/42/delete', 'POST')?->name(),
        );
    }

    public function test_a_path_that_names_no_row_is_rejected_where_it_is_declared(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must carry {id}');

        DeleteScreen::make('remove');
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id())
            ->screens(DeleteScreen::make())
            ->compile();
    }
}
