<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\PeopleModule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ShellFooterTest extends TestCase
{
    public function test_the_footer_the_application_supplies_sits_outside_the_frame(): void
    {
        $shell = $this->shell(['footer' => 'v1.2.0 · Hydra v0.6.2']);

        $this->assertStringContainsString('<footer class="admin-footer">v1.2.0 · Hydra v0.6.2</footer>', $shell);
        $this->assertGreaterThan(strpos($shell, 'id="admin-frame"'), strpos($shell, 'admin-footer'));
    }

    public function test_no_footer_renders_no_footer(): void
    {
        $this->assertStringNotContainsString('admin-footer', $this->shell());
        $this->assertStringNotContainsString('admin-footer', $this->shell(['footer' => '']));
    }

    /** @param array<string, mixed> $data */
    private function shell(array $data = []): string
    {
        $admin = new AdminHarness(
            [PeopleModule::class => new PeopleModule, ArraySource::class => new ArraySource],
            [PeopleModule::class],
        );

        return $admin->view->render('admin/partials/shell', [
            'screen' => $admin->chrome->root('Home'),
            'content' => '',
            ...$data,
        ], layout: false);
    }
}
