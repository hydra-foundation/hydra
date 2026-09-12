<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Blueprint;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\ModuleScanner;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Auth\AuthenticateMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Modules compile to the same plain route definitions RouteScanner emits for
 * controllers, under a configurable and normalized prefix.
 */
final class ModuleScannerTest extends TestCase
{
    public function test_it_compiles_a_module_to_a_plain_route_definition(): void
    {
        $routes = (new ModuleScanner)->scan(
            [$this->blueprint('users')],
            '/admin',
            [AuthenticateMiddleware::class],
        );

        $this->assertSame([[
            'method' => 'GET',
            'path' => '/admin/users',
            'handler' => [AdminController::class, 'list'],
            'middleware' => [AuthenticateMiddleware::class],
            'name' => 'users.list',
        ]], $routes);
    }

    public function test_the_prefix_is_configurable_and_normalized(): void
    {
        $routes = (new ModuleScanner)->scan([$this->blueprint('users')], '/backstage/');

        $this->assertSame('/backstage/users', $routes[0]['path']);
    }

    private function blueprint(string $slug): Blueprint
    {
        return Definition::make($slug)
            ->source(new ArraySource)
            ->fields(Field::text('username'))
            ->compile();
    }
}
