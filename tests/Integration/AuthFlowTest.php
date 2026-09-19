<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Tests\Fixture\Entities\Role;
use Hydra\Tests\Fixture\Fixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The auth slice end to end: the login form, a failed attempt, a successful
 * one, the htmx variant, logout, and the guard turning an anonymous visitor
 * away from a protected route.
 */
#[CoversNothing]
final class AuthFlowTest extends TestCase
{
    private const USERNAME = 'will';

    private Client $http;

    protected function setUp(): void
    {
        $app = Fixture::boot();
        $app->seed(self::USERNAME, Role::Admin);
        $this->http = $app->http();
    }

    public function test_login_page_renders_the_form(): void
    {
        $this->http->get('/login')
            ->assertOk()
            ->assertSee('name="username"')
            ->assertSee('name="password"')
            ->assertSee('name="_token"');
    }

    public function test_protected_route_redirects_anonymous_browser_to_login(): void
    {
        $this->http->get('/admin')->assertStatus(302)->assertRedirect('/login');
    }

    public function test_protected_route_signals_login_to_htmx(): void
    {
        // A 302 would be followed by fetch and the login page swapped into one
        // element, so the redirect travels as a directive htmx 4 will act on.
        $this->http->htmx()->get('/admin')->assertOk()->assertHtmxRedirect('/login');
    }

    public function test_wrong_password_is_rejected_generically_without_logging_in(): void
    {
        $this->login('wrong')->assertStatus(422)->assertSee('match our records');

        $this->http->get('/admin')->assertStatus(302)->assertRedirect('/login');
    }

    public function test_empty_fields_show_required_errors(): void
    {
        $this->http->post('/login', ['username' => '', 'password' => ''])
            ->assertStatus(422)
            ->assertSee('Enter your username.')
            ->assertSee('Enter your password.')
            // A feedback span is only revealed next to a control marked invalid,
            // so the two have to travel together or the message renders into nothing.
            ->assertSee('id="username" class="form-control is-invalid"')
            ->assertSee('<span id="usernameFeedback" class="invalid-feedback">');
    }

    public function test_failed_htmx_login_returns_only_the_form(): void
    {
        $response = $this->http->htmx()
            ->post('/login', ['username' => self::USERNAME, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertSee('match our records')
            // 'credentials' matches no field, so it needs the form-level alert.
            ->assertSee('class="alert alert-danger"')
            // The swap target is the form, so the layout must not come with it.
            ->assertFragment();

        $this->assertStringStartsWith('<form id="login-form"', trim($response->body()));
    }

    public function test_successful_login_redirects_and_grants_the_protected_page(): void
    {
        $this->login()->assertStatus(302)->assertRedirect('/admin');

        // The admin root names the landing module rather than rendering itself.
        $this->http->get('/admin')->assertStatus(302)->assertRedirect('/admin/dashboard');

        $this->http->get('/admin/dashboard')->assertOk()->assertSee(self::USERNAME);
    }

    public function test_following_a_login_lands_on_the_dashboard(): void
    {
        $this->http->followingRedirects()
            ->post('/login', ['username' => self::USERNAME, 'password' => Fixture::PASSWORD])
            ->assertOk()
            ->assertSee(self::USERNAME);
    }

    public function test_htmx_login_signals_redirect(): void
    {
        // Not a 204: htmx 4 skips the body of one, and the sign-in button would
        // do nothing at all.
        $this->http->htmx()
            ->post('/login', ['username' => self::USERNAME, 'password' => Fixture::PASSWORD])
            ->assertOk()
            ->assertHtmxRedirect('/admin');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->login();
        $this->http->get('/admin/dashboard')->assertOk();

        $this->http->post('/logout')->assertStatus(302)->assertRedirect('/login');

        $this->http->get('/admin/dashboard')->assertStatus(302)->assertRedirect('/login');
    }

    /**
     * A form was loaded, the session then expired, and the submit arrives with
     * a token the fresh session knows nothing about. A GUEST failing that check
     * has to land on the login page rather than a bare 403, which is the whole
     * reason the redirect middleware looks at whether a token was ever issued.
     */
    public function test_expired_session_post_redirects_to_login_instead_of_403(): void
    {
        $this->http->unprepared()
            ->post('/login', [
                'username' => self::USERNAME,
                'password' => Fixture::PASSWORD,
                '_token' => 'token-from-the-expired-session',
            ])
            ->assertStatus(302)
            ->assertRedirect('/login');
    }

    public function test_expired_session_htmx_post_signals_login(): void
    {
        $this->http->htmx()
            ->withHeader('X-CSRF-Token', 'token-from-the-expired-session')
            ->post('/logout')
            ->assertHtmxRedirect('/login');
    }

    public function test_authenticated_user_with_bad_token_still_gets_403(): void
    {
        // A LIVE session with a wrong token is a real CSRF failure; the
        // redirect policy applies to guests only.
        $this->login();

        $this->http->withHeader('X-CSRF-Token', 'not-the-real-token')->post('/logout')->assertStatus(403);
    }

    private function login(string $password = Fixture::PASSWORD): TestResponse
    {
        return $this->http->post('/login', ['username' => self::USERNAME, 'password' => $password]);
    }
}
