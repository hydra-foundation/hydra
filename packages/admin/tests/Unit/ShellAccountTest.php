<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\Tests\Support\PeopleModule;
use Hydra\Core\Security\Signer;
use Hydra\Csrf\CsrfGuard;
use Hydra\Http\CspNonce;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\View\PhpView;
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

        $this->assertSame(1, substr_count($shell, '<div id="admin-account-topbar" class="admin-account-who"'));
        $this->assertSame(1, substr_count($shell, '<div id="admin-account-sidebar" class="admin-account-who"'));
        $this->assertSame(2, substr_count($shell, self::BADGE . '</div>'));
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

    public function test_the_slots_are_not_sent_out_of_band_on_a_whole_page(): void
    {
        $this->assertStringNotContainsString('admin-account-who" hx-swap-oob', $this->shell(['account' => self::BADGE]));
    }

    public function test_a_frame_swap_sends_the_account_to_both_places_out_of_band(): void
    {
        // The slot is outside the frame, so a picture changed on one screen
        // would otherwise wait for a full page load to show.
        $fragment = $this->fragment(dirname(__DIR__) . '/views-account');

        foreach (['topbar', 'sidebar'] as $place) {
            $this->assertMatchesRegularExpression(
                '~<div id="admin-account-' . $place . '" class="admin-account-who" hx-swap-oob="true" hx-nonce="[^"]+">' . preg_quote(self::BADGE, '~') . '</div>~',
                $fragment,
            );
        }
    }

    public function test_a_frame_swap_sends_no_slot_when_the_application_fills_none(): void
    {
        $this->assertStringNotContainsString('admin-account', $this->fragment());
    }

    private function fragment(?string $views = null): string
    {
        $admin = $this->admin();
        $view = $views === null ? $admin->view : new PhpView(
            $views,
            new CspNonce,
            new CsrfGuard(new ArraySessionStore, Signer::fromHex(str_repeat('ab', 32))),
            fallbacks: [dirname(__DIR__) . '/views', AdminServiceProvider::views()],
        );

        return $view->render('admin/fragment', [
            'screen' => $admin->chrome->root('Home'),
            'body' => 'admin/partials/errors',
            'toolbar' => null,
            'data' => ['errors' => []],
        ], layout: false);
    }

    private function admin(): AdminHarness
    {
        return new AdminHarness(
            [PeopleModule::class => new PeopleModule, ArraySource::class => new ArraySource],
            [PeopleModule::class],
        );
    }

    /** @param array<string, mixed> $data */
    private function shell(array $data = []): string
    {
        $admin = $this->admin();

        return $admin->view->render('admin/partials/shell', [
            'screen' => $admin->chrome->root('Home'),
            'content' => '',
            ...$data,
        ], layout: false);
    }
}
