<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\PeopleModule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The bell, beside the account on both the rail and the narrow top bar. It
 * draws itself empty and asks for its badge on load, and its list when it
 * opens; the badge is what listens.
 */
#[CoversNothing]
final class ShellBellTest extends TestCase
{
    private const ACCOUNT = '<a href="/admin/settings/account">clerk</a>';

    public function test_the_bell_rings_in_both_places_with_its_own_ids(): void
    {
        $shell = $this->shell(['account' => self::ACCOUNT, 'bell' => '/admin/notifications']);

        $this->assertSame(2, substr_count($shell, 'class="admin-bell dropdown"'));
        $this->assertStringContainsString('id="admin-bell-badge-topbar"', $shell);
        $this->assertStringContainsString('id="admin-bell-badge-sidebar"', $shell);
        $this->assertStringContainsString('hx-get="/admin/notifications/badge?place=topbar"', $shell);
        $this->assertStringContainsString('hx-get="/admin/notifications/badge?place=sidebar"', $shell);
    }

    public function test_the_badge_loads_itself_and_the_list_loads_when_the_menu_opens(): void
    {
        $bell = $this->bell($this->shell(['bell' => '/admin/notifications']));

        $this->assertStringContainsString('data-bs-toggle="dropdown"', $bell);
        $this->assertStringContainsString('aria-label="Notifications"', $bell);
        $this->assertMatchesRegularExpression('~id="admin-bell-badge-topbar"[^>]*hx-trigger="load"~s', $bell);
        // On the bell itself, where Bootstrap's show event bubbles to: htmx 4
        // reads a modifier up to the first space, so a from:closest .x
        // listener on the menu would never hear it.
        $this->assertMatchesRegularExpression('~<div class="admin-bell dropdown"[^>]*hx-get="/admin/notifications"[^>]*hx-trigger="show\.bs\.dropdown"[^>]*hx-target="find \.admin-bell-menu"[^>]*hx-swap="innerHTML"~s', $bell);
        $this->assertStringNotContainsString('from:', $bell);
        // Fixed, so the menu escapes the rail, which scrolls and would clip it.
        $this->assertStringContainsString("data-bs-popper-config='{\"strategy\":\"fixed\"}'", $bell);
    }

    public function test_it_sits_in_the_account_row(): void
    {
        $shell = $this->shell(['account' => self::ACCOUNT, 'bell' => '/admin/notifications']);

        $this->assertMatchesRegularExpression('~<div class="admin-account"><div id="admin-account-topbar" class="admin-account-who"[^>]*>' . preg_quote(self::ACCOUNT, '~') . '</div><div class="admin-bell dropdown"~', $shell);
    }

    public function test_without_a_bell_the_account_row_is_as_it_was(): void
    {
        $shell = $this->shell(['account' => self::ACCOUNT]);

        $this->assertSame(2, preg_match_all('~<div class="admin-account"><div id="admin-account-\w+" class="admin-account-who"[^>]*>' . preg_quote(self::ACCOUNT, '~') . '</div></div>~', $shell));
        $this->assertStringNotContainsString('admin-bell', $shell);
        $this->assertStringNotContainsString('admin-bell', $this->shell(['account' => self::ACCOUNT, 'bell' => '']));
    }

    public function test_a_bell_with_no_account_still_has_a_row(): void
    {
        $this->assertSame(2, substr_count($this->shell(['bell' => '/admin/notifications']), 'class="admin-bell dropdown"'));
    }

    private function bell(string $shell): string
    {
        $start = (int) strpos($shell, '<div class="admin-bell dropdown"');

        return substr($shell, $start, (int) strpos($shell, '<!-- /admin-bell -->', $start) - $start);
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
