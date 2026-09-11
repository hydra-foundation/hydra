<?php

declare(strict_types=1);

namespace Hydra\Session\Contracts;

/**
 * Session interface
 *
 * The everyday, controller-facing session: a request-scoped key/value store
 * that persists across requests for one client.
 */
interface SessionInterface
{
    /** The current session id. */
    public function id(): string;

    /**
     * Issue a fresh session id, keeping the data. Defends against session
     * fixation — call it on any privilege change (e.g. login). $deleteOld asks
     * the backend to drop the old session's storage.
     */
    public function regenerate(bool $deleteOld = true): void;

    /** Read a stored value, or $default when the key is absent. */
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    /** Whether the key holds a non-null value. */
    public function has(string $key): bool;

    public function remove(string $key): void;

    /** @return array<string, mixed> */
    public function all(): array;

    /** Empty the stored data. Pending flash is unaffected. */
    public function clear(): void;

    /** Stash a value readable only on the next request, then gone. */
    public function flash(string $key, mixed $value): void;

    /** Read a value flashed on the previous request, or $default. */
    public function getFlash(string $key, mixed $default = null): mixed;
}
