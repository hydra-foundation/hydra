<?php

declare(strict_types=1);

namespace Hydra\Session\Stores;

use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use LogicException;

/**
 * Shared session semantics: the data and flash behaviour every backend has in
 * common, with no opinion on where the bytes live.
 */
abstract class AbstractSession implements SessionInterface, SessionLifecycleInterface
{
    /** True between start() and save() — the only window data methods work. */
    protected bool $started = false;

    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<string, mixed> Flash visible this request (aged from "new"). */
    protected array $flashOld = [];

    /** @var array<string, mixed> Flash set this request, visible next request. */
    protected array $flashNew = [];

    abstract public function start(): void;

    abstract public function save(): void;

    abstract public function id(): string;

    abstract public function regenerate(bool $deleteOld = true): void;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->guardStarted();

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->guardStarted();

        // null is the absent value across the whole contract (get() defaults on
        // it, has() reports false), so storing it would make all() disagree with
        // both. Setting null therefore removes the key, keeping the three coherent.
        if ($value === null) {
            unset($this->data[$key]);
            return;
        }

        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->guardStarted();

        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        $this->guardStarted();

        unset($this->data[$key]);
    }

    public function all(): array
    {
        $this->guardStarted();

        return $this->data;
    }

    public function clear(): void
    {
        $this->guardStarted();

        $this->data = [];
    }

    public function flash(string $key, mixed $value): void
    {
        $this->guardStarted();

        $this->flashNew[$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $this->guardStarted();

        return $this->flashOld[$key] ?? $default;
    }

    /**
     * Age flash data: this request reads what the previous one set; whatever is
     * set this request waits for the next. The previous "old" is discarded.
     * Subclasses call this from start() once the backing state is loaded.
     */
    protected function ageFlash(): void
    {
        $this->flashOld = $this->flashNew;
        $this->flashNew = [];
    }

    /**
     * Fail loud on any data access outside the start()→save() window. A read
     * would see stale or empty state; a write would silently never persist.
     * Subclasses use it to guard id()/regenerate() with the same semantics —
     * regeneration especially is a security operation (fixation defense on
     * login) that must never silently no-op.
     */
    protected function guardStarted(): void
    {
        if (!$this->started) {
            throw new LogicException(
                'Session accessed outside its lifecycle: start() has not run, or save() has already '
                . 'closed it. Session data is only valid between StartSessionMiddleware opening and '
                . 'closing the request.'
            );
        }
    }
}
