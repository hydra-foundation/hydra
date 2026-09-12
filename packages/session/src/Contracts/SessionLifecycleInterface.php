<?php

declare(strict_types=1);

namespace Hydra\Session\Contracts;

/**
 * The lifecycle half of a session, held only by the framework's session
 * middleware and never handed to controllers
 */
interface SessionLifecycleInterface
{
    /**
     * Open the session and age flash data (promote this-request flash to
     * readable, discard the previous request's). Idempotent within a request
     */
    public function start(): void;

    /** Persist the session and release it for the rest of the request. */
    public function save(): void;
}
