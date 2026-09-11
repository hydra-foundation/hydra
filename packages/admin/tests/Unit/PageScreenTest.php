<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Screens\ListScreen;
use Hydra\Admin\Screens\PageScreen;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\SettingsController;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PageScreenTest extends TestCase
{
    public function test_a_page_screen_renders_through_the_admin_controller_by_default(): void
    {
        $screen = PageScreen::make('overview', 'admin/dashboard');

        $this->assertSame([AdminController::class, 'page'], $screen->handler());
        $this->assertSame('', $screen->path());
        $this->assertNull($screen->heading());
    }

    public function test_handed_by_points_a_screen_at_an_ordinary_controller_action(): void
    {
        $screen = PageScreen::make('report', 'admin/report')->handledBy(['App\Controllers\ReportController', 'index']);

        $this->assertSame(['App\Controllers\ReportController', 'index'], $screen->handler());
    }

    public function test_a_module_of_page_screens_needs_no_source_or_fields(): void
    {
        $routes = (new ModuleScanner)->scan(
            [
                Definition::make('dashboard')
                    ->screens(
                        PageScreen::make('overview', 'admin/dashboard'),
                        PageScreen::make('trends', 'admin/trends')->at('trends'),
                    )
                    ->compile(),
            ],
            '/admin',
        );

        $this->assertSame(
            ['/admin/dashboard', '/admin/dashboard/trends'],
            array_column($routes, 'path'),
        );
        $this->assertSame(['dashboard.overview', 'dashboard.trends'], array_column($routes, 'name'));
    }

    public function test_a_screen_ability_overrides_the_modules(): void
    {
        $blueprint = Definition::make('dashboard')
            ->ability('AccessAdmin')
            ->screens(PageScreen::make('overview', 'admin/dashboard')->requires('ViewReports'))
            ->compile();

        $this->assertSame('ViewReports', $blueprint->screen('overview')?->ability());
        $this->assertSame('AccessAdmin', $blueprint->ability);
    }

    public function test_two_screens_at_the_same_path_are_a_programming_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('two screens at "GET "');

        Definition::make('dashboard')
            ->source(new ArraySource)
            ->fields(Field::text('name'))
            ->screens(PageScreen::make('overview', 'admin/dashboard'))
            ->compile();
    }

    public function test_an_explicit_list_screen_still_suppresses_the_implicit_one(): void
    {
        $blueprint = Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::text('name'))
            ->screens(new ListScreen, PageScreen::make('report', 'admin/report')->at('report'))
            ->compile();

        $this->assertSame(['list', 'report'], array_map(
            static fn ($screen): string => $screen->name(),
            $blueprint->screens,
        ));
    }

    public function test_a_page_answers_no_post_until_it_is_given_somewhere_to_send_one(): void
    {
        // A dashboard reads. Emitting a POST route for it would put a second
        // way into a screen that has nothing to receive.
        $this->assertNull(PageScreen::make('overview', 'admin/dashboard')->submitHandler());
    }

    public function test_a_page_that_saves_routes_a_post_at_its_own_url(): void
    {
        $blueprint = Definition::make('settings')
            ->screens(
                PageScreen::make('appearance', 'admin/settings/appearance')
                    ->at('appearance')
                    ->submittedTo([SettingsController::class, 'save']),
            )
            ->compile();

        $routes = (new ModuleScanner)->scan([$blueprint], '/admin');

        $this->assertSame(
            [['GET', '/admin/settings/appearance'], ['POST', '/admin/settings/appearance']],
            array_map(static fn (array $route): array => [$route['method'], $route['path']], $routes),
        );
        $this->assertSame([SettingsController::class, 'save'], $routes[1]['handler']);
        $this->assertSame('settings.appearance.submit', $routes[1]['name']);
    }
}
