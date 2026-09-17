<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Http\HtmxResponse;
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

    private Fixture $app;

    protected function setUp(): void
    {
        $this->app = Fixture::boot();
        $this->app->seed(self::USERNAME, Role::Admin);
    }

    public function test_login_page_renders_the_form(): void
    {
        $response = $this->app->handle('GET', '/login');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('name="username"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="_token"', $body);
    }

    public function test_protected_route_redirects_anonymous_browser_to_login(): void
    {
        $response = $this->app->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_protected_route_signals_login_to_htmx(): void
    {
        $response = $this->app->handle('GET', '/admin', ['HX-Request' => 'true']);

        // A 302 would be followed by fetch and the login page swapped into one
        // element, so the redirect travels as a directive htmx 4 will act on.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/login', HtmxResponse::directive($response, 'redirect'));
    }

    public function test_wrong_password_is_rejected_generically_without_logging_in(): void
    {
        $response = $this->app->login(self::USERNAME, 'wrong');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('match our records', (string) $response->getBody());

        $bounced = $this->app->handle('GET', '/admin');
        $this->assertSame(302, $bounced->getStatusCode());
        $this->assertSame('/login', $bounced->getHeaderLine('Location'));
    }

    public function test_empty_fields_show_required_errors(): void
    {
        $response = $this->app->handle('POST', '/login', [], ['username' => '', 'password' => '']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Enter your username.', $body);
        $this->assertStringContainsString('Enter your password.', $body);
        // A feedback span is only revealed next to a control marked invalid, so
        // the two have to travel together or the message renders into nothing.
        $this->assertStringContainsString('id="username" class="form-control is-invalid"', $body);
        $this->assertStringContainsString('<span id="usernameFeedback" class="invalid-feedback">', $body);
    }

    public function test_failed_htmx_login_returns_only_the_form(): void
    {
        $response = $this->app->handle('POST', '/login', ['HX-Request' => 'true'], [
            'username' => self::USERNAME,
            'password' => 'wrong',
        ]);

        $body = (string) $response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('match our records', $body);
        // 'credentials' matches no field, so it needs the form-level alert.
        $this->assertStringContainsString('class="alert alert-danger"', $body);
        // The swap target is the form, so the layout must not come with it.
        $this->assertStringNotContainsString('<!doctype html>', $body);
        $this->assertStringStartsWith('<form id="login-form"', trim($body));
    }

    public function test_successful_login_redirects_and_grants_the_protected_page(): void
    {
        $login = $this->app->login(self::USERNAME);

        $this->assertSame(302, $login->getStatusCode());
        $this->assertSame('/admin', $login->getHeaderLine('Location'));

        // The admin root names the landing module rather than rendering itself.
        $root = $this->app->handle('GET', '/admin');
        $this->assertSame(302, $root->getStatusCode());
        $this->assertSame('/admin/dashboard', $root->getHeaderLine('Location'));

        $dashboard = $this->app->handle('GET', '/admin/dashboard');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringContainsString(self::USERNAME, (string) $dashboard->getBody());
    }

    public function test_htmx_login_signals_redirect(): void
    {
        $response = $this->app->handle('POST', '/login', ['HX-Request' => 'true'], [
            'username' => self::USERNAME,
            'password' => Fixture::PASSWORD,
        ]);

        // Not a 204: htmx 4 skips the body of one, and the sign-in button would
        // do nothing at all.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin', HtmxResponse::directive($response, 'redirect'));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->app->login(self::USERNAME);
        $this->assertSame(200, $this->app->handle('GET', '/admin/dashboard')->getStatusCode());

        $logout = $this->app->handle('POST', '/logout');
        $this->assertSame(302, $logout->getStatusCode());
        $this->assertSame('/login', $logout->getHeaderLine('Location'));

        $bounced = $this->app->handle('GET', '/admin/dashboard');
        $this->assertSame(302, $bounced->getStatusCode());
        $this->assertSame('/login', $bounced->getHeaderLine('Location'));
    }

    /**
     * A form was loaded, the session then expired, and the submit arrives with
     * a token the fresh session knows nothing about. A GUEST failing that check
     * has to land on the login page rather than a bare 403, which is the whole
     * reason the redirect middleware looks at whether a token was ever issued.
     */
    public function test_expired_session_post_redirects_to_login_instead_of_403(): void
    {
        $request = $this->app->make('POST', '/login')->withParsedBody([
            'username' => self::USERNAME,
            'password' => Fixture::PASSWORD,
            '_token' => 'token-from-the-expired-session',
        ]);

        $response = $this->app->send($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_expired_session_htmx_post_signals_login(): void
    {
        $request = $this->app->make('POST', '/logout')
            ->withHeader('HX-Request', 'true')
            ->withHeader('X-CSRF-Token', 'token-from-the-expired-session');

        $this->assertSame('/login', HtmxResponse::directive($this->app->send($request), 'redirect'));
    }

    public function test_authenticated_user_with_bad_token_still_gets_403(): void
    {
        // A LIVE session with a wrong token is a real CSRF failure; the
        // redirect policy applies to guests only.
        $this->app->login(self::USERNAME);

        $request = $this->app->make('POST', '/logout')->withHeader('X-CSRF-Token', 'not-the-real-token');

        $this->assertSame(403, $this->app->send($request)->getStatusCode());
    }
}
