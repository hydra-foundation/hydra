<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\PeopleModule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The slot an application fills with who is signed in. The admin knows nothing
 * about accounts, so it only says where the markup goes: beside the brand on a
 * narrow screen's top bar, and above Sign out on a wide screen's rail.
 */
#[CoversNothing]
final class ShellAccountTest extends TestCase
{
    private const BADGE = '<a href="/admin/settings/account">clerk</a>';

    public function test_the_account_shows_in_the_top_bar_and_the_rail(): void
    {
        $shell = $this->shell(['account' => self::BADGE]);

        $this->assertSame(2, substr_count($shell, '<div class="admin-account">' . self::BADGE . '</div>'));
        $this->assertLessThan(strpos($shell, 'admin-layout'), strpos($shell, 'admin-account'));
    }

    public function test_in_the_rail_it_sits_just_above_sign_out(): void
    {
        $shell = $this->shell(['account' => self::BADGE]);
        $rail = substr($shell, (int) strpos($shell, 'id="admin-sidebar"'));

        $this->assertLessThan(strpos($rail, 'admin-signout'), strpos($rail, 'admin-account'));
    }

    public function test_no_account_renders_no_slot(): void
    {
        $this->assertStringNotContainsString('admin-account', $this->shell());
        $this->assertStringNotContainsString('admin-account', $this->shell(['account' => '']));
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
