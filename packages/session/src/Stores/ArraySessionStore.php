<?php

declare(strict_types=1);

namespace Hydra\Session\Stores;

/**
 * A session store that touches no globals, so tests and CLI code get the full
 * contract (lifecycle guards included) without PHP's once-per-process session.
 */
final class ArraySessionStore extends SessionStore
{
    private string $id;

    public function __construct()
    {
        $this->id = $this->newId();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->ageFlash();
        $this->started = true;
    }

    public function save(): void
    {
        // Nothing to persist for an in-memory store, but the lifecycle still
        // closes, so post-save access fails loud like production.
        $this->started = false;
    }

    public function id(): string
    {
        $this->guardStarted();

        return $this->id;
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->guardStarted();

        // No backing storage to drop, so $deleteOld has no effect here; the data
        // and flash carry over to the new id.
        $this->id = $this->newId();
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
