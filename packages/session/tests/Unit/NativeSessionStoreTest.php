<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\SessionConfig;
use Hydra\Session\Stores\NativeSessionStore;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * NativeSessionStore drives PHP's global session machinery, which can only be
 * started once per process and mutates process-wide state (ini, headers,
 * $_SESSION). Each test therefore runs in its own process so it gets a fresh,
 * never-started session; global state is not preserved because the serialized
 * parent state cannot carry an active session handle anyway.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NativeSessionStoreTest extends TestCase
{
    protected function setUp(): void
    {
        // CLI has no real client; PHPUnit's process isolation buffers output,
        // so the session cookie header can still be "sent" harmlessly. Only the
        // cache limiter must go — it would emit headers session_start() warns on.
        ini_set('session.cache_limiter', '');
    }

    public function test_start_forces_strict_mode_regardless_of_ini(): void
    {
        // Simulate the vulnerable php.ini default the fix must override.
        ini_set('session.use_strict_mode', '0');

        $store = new NativeSessionStore(new SessionConfig);
        $store->start();

        // The fixation defense: start() must adopt strict mode itself instead
        // of trusting whatever the host's php.ini happens to say.
        $this->assertSame('1', ini_get('session.use_strict_mode'));
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function test_set_save_start_round_trips_through_the_hydra_namespace(): void
    {
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();
        $store->set('user_id', 42);
        $store->flash('status', 'saved');
        $store->save();

        // save() must persist under the single reserved key only.
        $this->assertSame(
            ['data' => ['user_id' => 42], 'flash' => ['status' => 'saved']],
            $_SESSION['_hydra'],
        );

        // A second start() (the "next request") rehydrates data and promotes
        // last request's flash into the readable bucket.
        $next = new NativeSessionStore(new SessionConfig);
        $next->start();

        $this->assertSame(42, $next->get('user_id'));
        $this->assertSame('saved', $next->getFlash('status'));
    }

    public function test_regenerate_changes_the_session_id(): void
    {
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();

        $before = $store->id();
        $store->regenerate();

        $this->assertNotSame('', $before);
        $this->assertNotSame($before, $store->id());
    }

    public function test_regenerate_outside_the_lifecycle_throws(): void
    {
        // Regeneration is the login fixation defense: silently not rotating
        // (the old no-op behavior) would be worse than failing. It must also
        // not start a session on its own.
        $store = new NativeSessionStore(new SessionConfig);

        try {
            $store->regenerate();
            $this->fail('regenerate() before start() should throw.');
        } catch (LogicException) {
        }

        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function test_double_start_is_a_noop(): void
    {
        // A second start() in the same request must not re-age (lose) flash
        // hydrated at the first one.
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();
        $store->set('user_id', 42);
        $store->start();

        $this->assertSame(42, $store->get('user_id'));
    }

    public function test_data_access_before_start_throws(): void
    {
        $store = new NativeSessionStore(new SessionConfig);

        $this->expectException(LogicException::class);
        $store->set('user_id', 42);
    }

    public function test_data_access_after_save_throws(): void
    {
        // The write-after-save window: session_write_close() has run, so a
        // write here would silently never persist — it must fail loud instead.
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();
        $store->save();

        $this->expectException(LogicException::class);
        $store->set('user_id', 42);
    }

    public function test_id_outside_the_lifecycle_throws(): void
    {
        // The old behavior returned '' before start() — indistinguishable from
        // a real (if odd) id at the call site. Fail loud instead.
        $store = new NativeSessionStore(new SessionConfig);

        $this->expectException(LogicException::class);
        $store->id();
    }

    public function test_id_is_the_native_session_id_between_start_and_save(): void
    {
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();

        $this->assertNotSame('', $store->id());
        $this->assertSame(session_id(), $store->id());
    }
}
