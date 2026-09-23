<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\PeopleModule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ShellBannerTest extends TestCase
{
    public function test_the_banner_the_application_supplies_sits_above_the_frame(): void
    {
        // Outside it, so a frame swap does not take the banner with it.
        $shell = $this->shell(['banner' => '<p>Verify your address</p>']);

        $this->assertStringContainsString('<div class="admin-banner"><p>Verify your address</p></div>', $shell);
        $this->assertLessThan(strpos($shell, 'id="admin-frame"'), strpos($shell, 'admin-banner'));
    }

    public function test_no_banner_renders_no_banner(): void
    {
        $this->assertStringNotContainsString('admin-banner', $this->shell());
        $this->assertStringNotContainsString('admin-banner', $this->shell(['banner' => '']));
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
