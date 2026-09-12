<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DashboardModule;
use Hydra\Admin\Tests\Support\UsersModule;
use PHPUnit\Framework\TestCase;

/**
 * The sidebar shows exactly the modules the gate allows, so no link can appear
 * for a screen that would answer 403.
 */
final class NavigationTest extends TestCase
{
    public function test_it_hides_modules_the_gate_denies(): void
    {
        $items = $this->navigation(allowed: false)->items();

        $this->assertSame(['dashboard'], array_column($items, 'slug'));
    }

    public function test_it_shows_every_module_the_gate_allows(): void
    {
        $items = $this->navigation(allowed: true)->items('users');

        $this->assertSame(['dashboard', 'users'], array_column($items, 'slug'));
        $this->assertSame('/admin/users', $items[1]['url']);
        $this->assertTrue($items[1]['active']);
        $this->assertFalse($items[0]['active']);
    }

    private function navigation(bool $allowed): Navigation
    {
        $container = new ArrayContainer([
            DashboardModule::class => new DashboardModule,
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
        ]);

        return new Navigation(
            new ModuleRegistry($container, [DashboardModule::class, UsersModule::class]),
            new AdminsOnlyGate($allowed),
        );
    }
}
