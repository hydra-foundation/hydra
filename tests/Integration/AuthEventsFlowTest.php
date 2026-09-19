<?php

declare(strict_types=1);

namespace Hydra\Tests\Integration;

use Hydra\Tests\Fixture\Fixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The piece no unit test can reach: that four packages are actually connected
 * in process, so an event the guard announces reaches auth's listener and lands
 * as a record in the logger.
 *
 * Nothing here mocks the event path. It drives real requests and reads the log
 * back, which is also what makes it the flow that catches a listener registered
 * against a dispatcher that was never bound — the failure mode where every
 * assertion about behaviour still passes and nothing is recorded.
 */
#[CoversNothing]
final class AuthEventsFlowTest extends TestCase
{
    private const USERNAME = 'will';

    private Fixture $app;

    protected function setUp(): void
    {
        $this->app = Fixture::boot();
        $this->app->seed(self::USERNAME);
    }

    public function test_successful_login_emits_attempting_then_login_audit_lines(): void
    {
        $this->app->login(self::USERNAME);

        // The whole chain fired: the guard dispatched, the listener logged.
        $this->assertContains('auth.attempting', $this->app->log()->messages());
        $this->assertContains('auth.login', $this->app->log()->messages());
        $this->assertNotContains('auth.login_failed', $this->app->log()->messages());

        // The identifier, not a password, is what got recorded.
        $this->assertArrayHasKey('user', $this->app->log()->firstWith('auth.login')['context']);
    }

    public function test_wrong_password_emits_login_failed_audit_line(): void
    {
        $this->app->login(self::USERNAME, 'wrong');

        $this->assertContains('auth.attempting', $this->app->log()->messages());
        $this->assertContains('auth.login_failed', $this->app->log()->messages());
        $this->assertNotContains('auth.login', $this->app->log()->messages());
        $this->assertSame('warning', $this->app->log()->firstWith('auth.login_failed')['level']);
    }

    public function test_logout_emits_logout_audit_line(): void
    {
        $this->app->login(self::USERNAME);
        $this->app->log()->clear();

        $this->app->http()->post('/logout')->assertRedirect('/login');

        $this->assertContains('auth.logout', $this->app->log()->messages());
        // The id captured before the session was cleared is carried on the event.
        $this->assertArrayHasKey('user', $this->app->log()->firstWith('auth.logout')['context']);
    }
}
