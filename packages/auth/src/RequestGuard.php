<?php

declare(strict_types=1);

namespace Hydra\Auth;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use LogicException;

/**
 * The guard for this request: the bearer token's owner once
 * {@see AuthenticateBearerMiddleware} has accepted one, the session otherwise.
 * A bearer request never consults the session, which it did not start.
 */
final class RequestGuard implements GuardInterface
{
    private ?AuthenticatedToken $bearer = null;

    public function __construct(private readonly GuardInterface $session) {}

    public function useToken(AuthenticatedToken $bearer): void
    {
        $this->bearer = $bearer;
    }

    /** The token this request was authenticated by, or null for a session request. */
    public function token(): ?ApiToken
    {
        return $this->bearer?->token;
    }

    public function check(): bool
    {
        return $this->bearer !== null || $this->session->check();
    }

    public function user(): ?AuthenticatableInterface
    {
        return $this->bearer !== null ? $this->bearer->user : $this->session->user();
    }

    public function id(): int|string|null
    {
        return $this->bearer !== null ? $this->bearer->user->getAuthIdentifier() : $this->session->id();
    }

    public function attempt(string $username, string $password): bool
    {
        return $this->session()->attempt($username, $password);
    }

    public function login(AuthenticatableInterface $user): void
    {
        $this->session()->login($user);
    }

    public function refresh(AuthenticatableInterface $user): void
    {
        $this->session()->refresh($user);
    }

    public function logout(): void
    {
        $this->session()->logout();
    }

    private function session(): GuardInterface
    {
        if ($this->bearer !== null) {
            throw new LogicException('A bearer-authenticated request has no session to sign in or out of.');
        }

        return $this->session;
    }
}
