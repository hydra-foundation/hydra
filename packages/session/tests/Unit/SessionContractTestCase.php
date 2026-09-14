<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every session store owes its callers, run against each
 * implementation and published so a third-party store can be held to it too.
 *
 * Sessions are the one place where a substitute backend is close to inevitable
 * — a pool of php-fpm workers behind a load balancer cannot use the native
 * files handler — and the parts a caller depends on are exactly the parts that
 * are easy to get subtly wrong: null meaning absent rather than stored, flash
 * living for one request and not two, and the lifecycle failing loud instead of
 * dropping a write nobody will ever read back.
 */
abstract class SessionContractTestCase extends TestCase
{
    /** A store that has not been started yet. */
    abstract protected function make(): SessionInterface&SessionLifecycleInterface;

    /**
     * Close $session and open the store the next request would see.
     *
     * Abstract because the boundary is where the two implementations legitimately
     * differ: the array store carries its own data across it, while the native
     * one writes to $_SESSION and a new instance reads it back, which is what a
     * second HTTP request actually does.
     */
    abstract protected function nextRequest(
        SessionInterface&SessionLifecycleInterface $session,
    ): SessionInterface&SessionLifecycleInterface;

    /** A started store, which is how the middleware hands one to a controller. */
    protected function session(): SessionInterface&SessionLifecycleInterface
    {
        $session = $this->make();
        $session->start();

        return $session;
    }

    public function test_it_satisfies_both_halves_of_the_contract(): void
    {
        $session = $this->make();

        $this->assertInstanceOf(SessionInterface::class, $session);
        $this->assertInstanceOf(SessionLifecycleInterface::class, $session);
    }

    public function test_get_returns_default_when_absent(): void
    {
        $session = $this->session();

        $this->assertNull($session->get('missing'));
        $this->assertSame('fallback', $session->get('missing', 'fallback'));
    }

    public function test_set_then_get(): void
    {
        $session = $this->session();
        $session->set('user', 42);

        $this->assertSame(42, $session->get('user'));
        $this->assertTrue($session->has('user'));
    }

    public function test_falsy_values_are_stored_not_treated_as_absent(): void
    {
        // The Required-rule lesson: '0'/0/false/'' are real values, not "missing".
        $session = $this->session();
        $session->set('zero', 0);
        $session->set('empty', '');
        $session->set('false', false);

        $this->assertTrue($session->has('zero'));
        $this->assertTrue($session->has('empty'));
        $this->assertTrue($session->has('false'));
        $this->assertSame(0, $session->get('zero', 'default'));
        $this->assertSame('', $session->get('empty', 'default'));
        $this->assertFalse($session->get('false', 'default'));
    }

    public function test_setting_null_removes_the_key_everywhere(): void
    {
        // null is the absent value across the whole contract: get() defaults on
        // it, has() is false, and it must not linger in all() either.
        $session = $this->session();
        $session->set('keep', 1);
        $session->set('nothing', null);

        $this->assertFalse($session->has('nothing'));
        $this->assertNull($session->get('nothing'));
        $this->assertSame(['keep' => 1], $session->all());
    }

    public function test_setting_null_clears_an_existing_key(): void
    {
        $session = $this->session();
        $session->set('a', 1);
        $session->set('a', null);

        $this->assertFalse($session->has('a'));
        $this->assertSame([], $session->all());
    }

    public function test_remove(): void
    {
        $session = $this->session();
        $session->set('a', 1);
        $session->remove('a');

        $this->assertFalse($session->has('a'));
        $this->assertNull($session->get('a'));
    }

    public function test_remove_absent_key_is_noop(): void
    {
        $session = $this->session();
        $session->remove('never-set');

        $this->assertFalse($session->has('never-set'));
    }

    public function test_all_returns_stored_data(): void
    {
        $session = $this->session();
        $session->set('a', 1);
        $session->set('b', 2);

        $this->assertSame(['a' => 1, 'b' => 2], $session->all());
    }

    public function test_clear_empties_data(): void
    {
        $session = $this->session();
        $session->set('a', 1);
        $session->clear();

        $this->assertSame([], $session->all());
        $this->assertFalse($session->has('a'));
    }

    public function test_clear_leaves_pending_flash_alone(): void
    {
        // clear() is the data half only. A logout that wiped the flash it had
        // just set would lose the message it logged out to show.
        $session = $this->session();
        $session->set('a', 1);
        $session->flash('status', 'signed out');
        $session->clear();

        $this->assertSame('signed out', $this->nextRequest($session)->flashed('status'));
    }

    public function test_flash_is_not_visible_in_the_request_it_was_set(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');

        // Readable only on the NEXT request, not this one.
        $this->assertNull($session->flashed('status'));
        $this->assertSame('none', $session->flashed('status', 'none'));
    }

    public function test_flash_is_visible_on_the_next_request(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');

        $this->assertSame('saved', $this->nextRequest($session)->flashed('status'));
    }

    public function test_flash_expires_after_one_request(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');

        $session = $this->nextRequest($session); // next request: visible
        $this->assertSame('saved', $session->flashed('status'));

        $session = $this->nextRequest($session); // the one after: gone
        $this->assertNull($session->flashed('status'));
    }

    public function test_flash_does_not_leak_into_data(): void
    {
        $session = $this->session();
        $session->flash('status', 'saved');
        $session = $this->nextRequest($session);

        // Flash lives in its own bucket, never exposed through all()/get().
        $this->assertSame([], $session->all());
        $this->assertNull($session->get('status'));
    }

    public function test_id_is_stable_until_regenerated(): void
    {
        $session = $this->session();
        $first = $session->id();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $session->id());
    }

    public function test_regenerate_changes_the_id_but_keeps_data(): void
    {
        $session = $this->session();
        $session->set('user', 42);
        $before = $session->id();

        $session->regenerate();

        $this->assertNotSame($before, $session->id());
        $this->assertSame(42, $session->get('user'));
    }

    public function test_data_access_before_start_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->make()->set('user', 42);
    }

    public function test_reads_before_start_throw_too(): void
    {
        $this->expectException(LogicException::class);
        $this->make()->get('user');
    }

    public function test_flash_before_start_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->make()->flash('status', 'saved');
    }

    public function test_id_outside_the_lifecycle_throws(): void
    {
        // Returning '' before start() is indistinguishable from a real (if odd)
        // id at the call site. Fail loud instead.
        $this->expectException(LogicException::class);
        $this->make()->id();
    }

    public function test_data_access_after_save_throws(): void
    {
        // The write-after-save window: the backend is closed, so a write here
        // would silently never persist and must fail loud instead.
        $session = $this->session();
        $session->save();

        $this->expectException(LogicException::class);
        $session->set('user', 42);
    }

    public function test_regenerate_outside_the_lifecycle_throws(): void
    {
        // Regeneration is the login fixation defense, so silently not rotating
        // would be worse than failing.
        $session = $this->session();
        $session->save();

        $this->expectException(LogicException::class);
        $session->regenerate();
    }

    public function test_double_start_is_a_noop(): void
    {
        // A second start() in the same request must not re-age (and so lose)
        // the flash the first one promoted.
        $session = $this->session();
        $session->flash('status', 'saved');
        $session->start();

        $this->assertSame('saved', $this->nextRequest($session)->flashed('status'));
    }
}
