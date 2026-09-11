<?php

declare(strict_types=1);

namespace Hydra\Session\Stores;

use Hydra\Session\SessionConfig;

/**
 * Native session store
 *
 * The production session backend, built on PHP's native session.
 */
final class NativeSessionStore extends AbstractSession
{
    /** Reserved $_SESSION key holding this session's data + pending flash. */
    private const STORAGE_KEY = '_hydra';

    public function __construct(private readonly SessionConfig $config) {}

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name($this->config->name);
            session_set_cookie_params($this->config->cookieParams());
            // Session-fixation defense: with strict mode off (PHP's default),
            // session_start() adopts any uninitialized id an attacker plants in
            // the cookie, letting them pre-choose a victim's session id. Forcing
            // it here — rather than trusting php.ini, which varies per host —
            // guarantees unknown ids are rejected and replaced on every deploy.
            session_start(['use_strict_mode' => true]);
        }

        $stored = $_SESSION[self::STORAGE_KEY] ?? [];
        $this->data = $stored['data'] ?? [];
        // Whatever was flashed last request lands in "new"; ageFlash() promotes
        // it to the "old" bucket that getFlash() reads this request.
        $this->flashNew = $stored['flash'] ?? [];
        $this->flashOld = [];
        $this->ageFlash();

        $this->started = true;
    }

    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        // Persist only data + the flash set this request; the "old" bucket is
        // intentionally dropped so flash never survives more than one hop.
        $_SESSION[self::STORAGE_KEY] = [
            'data' => $this->data,
            'flash' => $this->flashNew,
        ];

        session_write_close();
        $this->started = false;
    }

    public function id(): string
    {
        $this->guardStarted();

        return session_id() ?: '';
    }

    public function regenerate(bool $deleteOld = true): void
    {
        // Silently not rotating would be worse than failing (see the guard's
        // docblock), so this throws rather than no-ops outside the lifecycle.
        $this->guardStarted();

        session_regenerate_id($deleteOld);
    }
}
