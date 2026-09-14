<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\Stores\ArraySessionStore;
use Hydra\Session\Stores\SessionStore;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The in-memory store against the shared contract. It is the reference
 * implementation, so it has nothing left that is its own: every expectation it
 * meets is one the contract states, which is the point of stating them there.
 */
#[CoversClass(SessionStore::class)]
#[CoversClass(ArraySessionStore::class)]
final class ArraySessionStoreTest extends SessionContractTestCase
{
    protected function make(): SessionInterface&SessionLifecycleInterface
    {
        return new ArraySessionStore;
    }

    protected function nextRequest(
        SessionInterface&SessionLifecycleInterface $session,
    ): SessionInterface&SessionLifecycleInterface {
        // The store holds its own data, so the same instance is the next
        // request; save() and start() are the brackets the middleware puts
        // around one.
        $session->save();
        $session->start();

        return $session;
    }
}
