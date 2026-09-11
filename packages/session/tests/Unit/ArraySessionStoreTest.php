<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\Stores\ArraySessionStore;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ArraySessionStoreTest extends TestCase
{
    private ArraySessionStore $session;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore;
        $this->session->start();
    }

    public function test_it_satisfies_both_halves_of_the_contract(): void
    {
        $this->assertInstanceOf(SessionInterface::class, $this->session);
        $this->assertInstanceOf(SessionLifecycleInterface::class, $this->session);
    }

    public function test_get_returns_default_when_absent(): void
    {
        $this->assertNull($this->session->get('missing'));
        $this->assertSame('fallback', $this->session->get('missing', 'fallback'));
    }

    public function test_set_then_get(): void
    {
        $this->session->set('user', 42);

        $this->assertSame(42, $this->session->get('user'));
        $this->assertTrue($this->session->has('user'));
    }

    public function test_falsy_values_are_stored_not_treated_as_absent(): void
    {
        // The Required-rule lesson: '0'/0/false/'' are real values, not "missing".
        $this->session->set('zero', 0);
        $this->session->set('empty', '');
        $this->session->set('false', false);

        $this->assertTrue($this->session->has('zero'));
        $this->assertTrue($this->session->has('empty'));
        $this->assertTrue($this->session->has('false'));
        $this->assertSame(0, $this->session->get('zero', 'default'));
        $this->assertSame('', $this->session->get('empty', 'default'));
        $this->assertFalse($this->session->get('false', 'default'));
    }

    public function test_setting_null_removes_the_key_everywhere(): void
    {
        // null is the absent value across the whole contract: get() defaults on
        // it, has() is false, and it must not linger in all() either.
        $this->session->set('keep', 1);
        $this->session->set('nothing', null);

        $this->assertFalse($this->session->has('nothing'));
        $this->assertNull($this->session->get('nothing'));
        $this->assertSame(['keep' => 1], $this->session->all());
    }

    public function test_setting_null_clears_an_existing_key(): void
    {
        $this->session->set('a', 1);
        $this->session->set('a', null);

        $this->assertFalse($this->session->has('a'));
        $this->assertSame([], $this->session->all());
    }

    public function test_remove(): void
    {
        $this->session->set('a', 1);
        $this->session->remove('a');

        $this->assertFalse($this->session->has('a'));
        $this->assertNull($this->session->get('a'));
    }

    public function test_remove_absent_key_is_noop(): void
    {
        $this->session->remove('never-set');

        $this->assertFalse($this->session->has('never-set'));
    }

    public function test_all_returns_stored_data(): void
    {
        $this->session->set('a', 1);
        $this->session->set('b', 2);

        $this->assertSame(['a' => 1, 'b' => 2], $this->session->all());
    }

    public function test_clear_empties_data(): void
    {
        $this->session->set('a', 1);
        $this->session->clear();

        $this->assertSame([], $this->session->all());
        $this->assertFalse($this->session->has('a'));
    }

    public function test_flash_is_not_visible_in_the_request_it_was_set(): void
    {
        $this->session->flash('status', 'saved');

        // Readable only on the NEXT request, not this one.
        $this->assertNull($this->session->getFlash('status'));
        $this->assertSame('none', $this->session->getFlash('status', 'none'));
    }

    public function test_flash_is_visible_on_the_next_request(): void
    {
        $this->session->flash('status', 'saved');

        // save() + start() simulates the next request's middleware lifecycle.
        $this->nextRequest();

        $this->assertSame('saved', $this->session->getFlash('status'));
    }

    public function test_flash_expires_after_one_request(): void
    {
        $this->session->flash('status', 'saved');

        $this->nextRequest(); // next request: visible
        $this->assertSame('saved', $this->session->getFlash('status'));

        $this->nextRequest(); // request after: gone
        $this->assertNull($this->session->getFlash('status'));
    }

    public function test_flash_does_not_leak_into_data(): void
    {
        $this->session->flash('status', 'saved');
        $this->nextRequest();

        // Flash lives in its own bucket — it is never exposed through all()/get().
        $this->assertSame([], $this->session->all());
        $this->assertNull($this->session->get('status'));
    }

    public function test_id_is_stable_until_regenerated(): void
    {
        $first = $this->session->id();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $this->session->id());
    }

    public function test_regenerate_changes_the_id_but_keeps_data(): void
    {
        $this->session->set('user', 42);
        $before = $this->session->id();

        $this->session->regenerate();

        $this->assertNotSame($before, $this->session->id());
        $this->assertSame(42, $this->session->get('user'));
    }

    public function test_data_access_before_start_throws(): void
    {
        $fresh = new ArraySessionStore;

        $this->expectException(LogicException::class);
        $fresh->set('user', 42);
    }

    public function test_reads_before_start_throw_too(): void
    {
        $fresh = new ArraySessionStore;

        $this->expectException(LogicException::class);
        $fresh->get('user');
    }

    public function test_data_access_after_save_throws(): void
    {
        // The write-after-save window: a write here would silently never
        // persist in production, so the reference store fails loud the same way.
        $this->session->save();

        $this->expectException(LogicException::class);
        $this->session->set('user', 42);
    }

    public function test_flash_before_start_throws(): void
    {
        $fresh = new ArraySessionStore;

        $this->expectException(LogicException::class);
        $fresh->flash('status', 'saved');
    }

    public function test_id_outside_the_lifecycle_throws(): void
    {
        $fresh = new ArraySessionStore;

        $this->expectException(LogicException::class);
        $fresh->id();
    }

    public function test_regenerate_outside_the_lifecycle_throws(): void
    {
        // Regeneration is the login fixation defense — silently not rotating
        // would be worse than failing.
        $this->session->save();

        $this->expectException(LogicException::class);
        $this->session->regenerate();
    }

    public function test_double_start_is_a_noop(): void
    {
        // A second start() in the same request must not re-age (and so lose) flash.
        $this->session->flash('status', 'saved');
        $this->session->start();

        $this->nextRequest();
        $this->assertSame('saved', $this->session->getFlash('status'));
    }

    /** Close and reopen the store the way the middleware brackets a request. */
    private function nextRequest(): void
    {
        $this->session->save();
        $this->session->start();
    }
}
