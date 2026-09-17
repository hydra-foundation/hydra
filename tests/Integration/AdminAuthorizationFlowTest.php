<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Tests\Fixture\Entities\Role;
use Hydra\Tests\Fixture\Fixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The gate on the backend as a whole, which is not the gate on a module.
 *
 * The area is behind being signed in, not behind holding a role, so the split
 * under test is anonymous against authenticated: an anonymous visitor gets the
 * 401 that auth's policy maps to a redirect, and a plain user gets the same 200
 * an admin does. A module that wants more says so itself.
 */
#[CoversNothing]
final class AdminAuthorizationFlowTest extends TestCase
{
    private Fixture $app;

    protected function setUp(): void
    {
        $this->app = Fixture::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk');
    }

    public function test_anonymous_visitor_is_redirected_to_login_not_forbidden(): void
    {
        // The auth guard fires first: not-signed-in is a 401 mapped to a
        // redirect, never the 403 — an anonymous visitor is not told the page
        // exists for somebody.
        $response = $this->app->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_the_admin_root_names_the_landing_module(): void
    {
        $this->login('clerk');

        $response = $this->app->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
    }

    public function test_logged_in_plain_user_reaches_the_admin_page(): void
    {
        $this->login('clerk');

        $response = $this->app->handle('GET', '/admin/dashboard');

        // Signing in is the whole gate: no role check stands between a standard
        // user and the backend.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('clerk', (string) $response->getBody());
    }

    public function test_logged_in_admin_reaches_the_admin_page(): void
    {
        $this->login('boss');

        $response = $this->app->handle('GET', '/admin/dashboard');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('boss', $body);
    }

    private function login(string $username): void
    {
        $this->assertSame(
            302,
            $this->app->login($username)->getStatusCode(),
            "login as {$username} should succeed",
        );
    }
}
