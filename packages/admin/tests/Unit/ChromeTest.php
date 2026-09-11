<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Navigation;
use Hydra\Admin\Tests\Support\AdminsOnlyGate;
use Hydra\Admin\Tests\Support\ArrayContainer;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\DashboardModule;
use Hydra\Admin\Tests\Support\UsersModule;
use PHPUnit\Framework\TestCase;

final class ChromeTest extends TestCase
{
    public function test_a_module_screen_hangs_under_the_admin_root(): void
    {
        $screen = $this->chrome()->module($this->blueprint('users'));

        $this->assertSame('Users', $screen->title);
        $this->assertSame(
            [['label' => 'Admin', 'url' => '/admin/dashboard'], ['label' => 'Users', 'url' => null]],
            $screen->breadcrumbs,
        );
    }

    public function test_a_nested_screen_keeps_the_module_above_it(): void
    {
        $screen = $this->chrome()->screen($this->blueprint('users'), 'Edit user', 'Edit 42');

        $this->assertSame('Edit user', $screen->title);
        $this->assertSame(
            [
                ['label' => 'Admin', 'url' => '/admin/dashboard'],
                ['label' => 'Users', 'url' => '/admin/users'],
                ['label' => 'Edit 42', 'url' => null],
            ],
            $screen->breadcrumbs,
        );
    }

    public function test_a_nested_screen_falls_back_to_its_title_for_the_crumb(): void
    {
        $screen = $this->chrome()->screen($this->blueprint('users'), 'Trends');

        $this->assertSame(['Admin', 'Users', 'Trends'], array_column($screen->breadcrumbs, 'label'));
    }

    public function test_the_root_crumb_skips_a_module_the_gate_denies(): void
    {
        $order = [UsersModule::class, DashboardModule::class];

        $this->assertSame('/admin/users', $this->chrome(order: $order)->module($this->blueprint('users'))->breadcrumbs[0]['url']);
        $this->assertSame('/admin/dashboard', $this->chrome(false, $order)->module($this->blueprint('users'))->breadcrumbs[0]['url']);
    }

    private function blueprint(string $slug): Blueprint
    {
        return $this->registry()->find($slug) ?? self::fail("No module registered at \"{$slug}\".");
    }

    /** @param list<class-string<\Hydra\Admin\Contracts\ModuleInterface>> $order */
    private function chrome(bool $allowed = true, ?array $order = null): Chrome
    {
        $registry = $this->registry($order);

        return new Chrome($registry, new Navigation($registry, new AdminsOnlyGate($allowed)));
    }

    /** @param list<class-string<\Hydra\Admin\Contracts\ModuleInterface>>|null $order */
    private function registry(?array $order = null): ModuleRegistry
    {
        $container = new ArrayContainer([
            DashboardModule::class => new DashboardModule,
            UsersModule::class => new UsersModule,
            ArraySource::class => new ArraySource,
        ]);

        return new ModuleRegistry($container, $order ?? [DashboardModule::class, UsersModule::class]);
    }
}
