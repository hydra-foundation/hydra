<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\SessionConfig;
use Hydra\Session\Stores\NativeSessionStore;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\Stores\SessionStore;
use Hydra\Session\Testing\SessionContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * NativeSessionStore drives PHP's global session machinery, which can only be
 * started once per process and mutates process-wide state (ini, headers,
 * $_SESSION). Each test therefore runs in its own process so it gets a fresh,
 * never-started session; global state is not preserved because the serialized
 * parent state cannot carry an active session handle anyway.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(SessionStore::class)]
#[CoversClass(NativeSessionStore::class)]
final class NativeSessionStoreTest extends SessionContractTestCase
{
    protected function setUp(): void
    {
        // CLI has no real client; PHPUnit's process isolation buffers output,
        // so the session cookie header can still be "sent" harmlessly. Only the
        // cache limiter must go, since it emits headers session_start() warns on.
        ini_set('session.cache_limiter', '');
    }

    protected function make(): SessionInterface&SessionLifecycleInterface
    {
        return new NativeSessionStore(new SessionConfig);
    }

    protected function nextRequest(
        SessionInterface&SessionLifecycleInterface $session,
    ): SessionInterface&SessionLifecycleInterface {
        // A real second request is a new store reading $_SESSION back, not the
        // same object reopened; the native store keeps nothing of its own.
        $session->save();

        return $this->session();
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
        $this->assertSame('saved', $next->flashed('status'));
    }







    public function test_id_is_the_native_session_id_between_start_and_save(): void
    {
        $store = new NativeSessionStore(new SessionConfig);
        $store->start();

        $this->assertNotSame('', $store->id());
        $this->assertSame(session_id(), $store->id());
    }
}
