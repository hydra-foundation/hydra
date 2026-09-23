<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use PHPUnit\Framework\Assert;

/**
 * A guard that is signed in as whoever it is told and records what was asked
 * of it, for asserting on.
 *
 * The shipped {@see \Hydra\Auth\SessionGuard} needs a started session, a user
 * provider and a hasher before it can say who is signed in, so a test about a
 * middleware or a controller that only wants "as this user" or "as nobody" has
 * to build all three. This holds the user directly.
 *
 * attempt() accepts only credentials named by {@see accepting()}, compared as
 * plaintext: nothing here is a password store, and a test that wants hashing
 * under test wants the real guard.
 */
final class FakeGuard implements GuardInterface
{
    /** @var array<string, array{password: string, user: AuthenticatableInterface}> */
    private array $credentials = [];

    /** @var list<array{username: string, succeeded: bool}> */
    private array $attempts = [];

    private int $logouts = 0;

    public function __construct(private ?AuthenticatableInterface $user = null) {}

    public static function guest(): self
    {
        return new self;
    }

    public static function signedInAs(AuthenticatableInterface $user): self
    {
        return new self($user);
    }

    public function accepting(string $username, string $password, AuthenticatableInterface $user): self
    {
        $this->credentials[$username] = ['password' => $password, 'user' => $user];

        return $this;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function user(): ?AuthenticatableInterface
    {
        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    public function attempt(string $username, string $password): bool
    {
        $known = $this->credentials[$username] ?? null;
        $succeeded = $known !== null && $password !== '' && hash_equals($known['password'], $password);

        $this->attempts[] = ['username' => $username, 'succeeded' => $succeeded];

        if ($succeeded) {
            $this->login($known['user']);
        }

        return $succeeded;
    }

    public function login(AuthenticatableInterface $user): void
    {
        $this->user = $user;
    }

    public function refresh(AuthenticatableInterface $user): void
    {
        $this->user = $user;
    }

    public function logout(): void
    {
        $this->user = null;
        $this->logouts++;
    }

    /**
     * Every attempt made, oldest first.
     *
     * @return list<array{username: string, succeeded: bool}>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * Someone is signed in, or this user is when given. The comparison is by
     * identifier, since a controller that reloaded the user holds an equal
     * object rather than the same one.
     */
    public function assertSignedIn(?AuthenticatableInterface $user = null): void
    {
        Assert::assertNotNull($this->user, 'Nobody is signed in.');

        if ($user !== null) {
            Assert::assertSame(
                $user->getAuthIdentifier(),
                $this->user->getAuthIdentifier(),
                'A different user is signed in.',
            );
        }
    }

    public function assertGuest(): void
    {
        Assert::assertNull($this->user, 'Somebody is signed in.');
    }

    /** An attempt was made for this username, and succeeded or failed when that is given. */
    public function assertAttempted(string $username, ?bool $succeeded = null): void
    {
        $matching = array_filter(
            $this->attempts,
            static fn (array $attempt): bool => $attempt['username'] === $username
                && ($succeeded === null || $attempt['succeeded'] === $succeeded),
        );

        $outcome = match ($succeeded) {
            true => ' successful',
            false => ' failed',
            null => '',
        };

        Assert::assertNotEmpty($matching, "No{$outcome} attempt was made for {$username}.");
    }

    public function assertNotAttempted(): void
    {
        Assert::assertSame([], $this->attempts, count($this->attempts) . ' attempts were made.');
    }

    public function assertLoggedOut(): void
    {
        Assert::assertGreaterThan(0, $this->logouts, 'logout() was never called.');
    }
}
