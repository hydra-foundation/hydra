<?php

declare(strict_types=1);

namespace Hydra\Authorization\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Authorization\Contracts\AbilityInterface;
use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Authorization\Gate;
use Hydra\Core\Contracts\ContainerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The gate is driven against a real ability resolved through a fake container
 * and a fake guard (the two seams it sits on). The abilities themselves are real
 * objects implementing the actual contract, so these prove the real
 * compose-user-with-ability path, not a mock of the verdict.
 */
final class GateTest extends TestCase
{
    private FakeGuard $guard;
    private FakeContainer $container;

    protected function setUp(): void
    {
        $this->guard = new FakeGuard;
        $this->container = new FakeContainer;
    }

    private function gate(): Gate
    {
        return new Gate($this->container, $this->guard);
    }

    public function test_allows_when_the_ability_grants(): void
    {
        $this->assertTrue($this->gate()->allows(AlwaysAllow::class));
    }

    public function test_allows_is_false_when_the_ability_denies(): void
    {
        $this->assertFalse($this->gate()->allows(AlwaysDeny::class));
    }

    public function test_denies_is_the_negation_of_allows(): void
    {
        $gate = $this->gate();

        $this->assertTrue($gate->denies(AlwaysDeny::class));
        $this->assertFalse($gate->denies(AlwaysAllow::class));
    }

    public function test_authorize_returns_silently_when_allowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->gate()->authorize(AlwaysAllow::class);
    }

    public function test_authorize_throws_403_when_denied(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->gate()->authorize(AlwaysDeny::class);
    }

    public function test_authorize_exception_carries_403(): void
    {
        try {
            $this->gate()->authorize(AlwaysDeny::class);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function test_the_current_user_is_passed_to_the_ability(): void
    {
        $user = new FakeUser(7);
        $this->guard->user = $user;
        $ability = new RecordingAbility;
        $this->container->register(RecordingAbility::class, $ability);

        $this->gate()->allows(RecordingAbility::class);

        $this->assertSame($user, $ability->seenUser);
    }

    public function test_a_null_user_reaches_the_ability(): void
    {
        // No one is logged in; the ability still runs and decides what null means.
        $ability = new RecordingAbility;
        $this->container->register(RecordingAbility::class, $ability);

        $this->gate()->allows(RecordingAbility::class);

        $this->assertTrue($ability->called);
        $this->assertNull($ability->seenUser);
    }

    public function test_the_subject_is_passed_through(): void
    {
        $subject = new \stdClass;
        $ability = new RecordingAbility;
        $this->container->register(RecordingAbility::class, $ability);

        $this->gate()->allows(RecordingAbility::class, $subject);

        $this->assertSame($subject, $ability->seenSubject);
    }

    public function test_a_non_ability_class_string_fails_loudly(): void
    {
        // A programming error (wrong class-string), not an authorization outcome:
        // it must throw, never silently deny.
        $this->expectException(InvalidArgumentException::class);

        $this->gate()->allows(\stdClass::class);
    }
}

/** An ability that always grants. */
final class AlwaysAllow implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return true;
    }
}

/** An ability that always denies. */
final class AlwaysDeny implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return false;
    }
}

/** Records what it was handed so the gate's wiring can be asserted. */
final class RecordingAbility implements AbilityInterface
{
    public bool $called = false;
    public ?AuthenticatableInterface $seenUser = null;
    public mixed $seenSubject = null;

    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        $this->called = true;
        $this->seenUser = $user;
        $this->seenSubject = $subject;

        return true;
    }
}

/** Minimal authenticatable for the tests. */
final class FakeUser implements AuthenticatableInterface
{
    public function __construct(private readonly int|string $id) {}

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }
}

/** A guard whose current user is set directly by the test. */
final class FakeGuard implements GuardInterface
{
    public ?AuthenticatableInterface $user = null;

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
        return false;
    }

    public function login(AuthenticatableInterface $user): void
    {
        $this->user = $user;
    }

    public function logout(): void
    {
        $this->user = null;
    }
}

/**
 * A container that returns registered instances and otherwise autowires a
 * no-arg class — enough to stand in for the app container's resolution of
 * ability class-strings.
 */
final class FakeContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $instances = [];

    public function register(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): mixed
    {
        return $this->instances[$id] ??= new $id();
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || class_exists($id);
    }

    public function singleton(string $abstract, callable|string $concrete): void {}

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function bound(string $abstract): bool
    {
        return isset($this->instances[$abstract]);
    }
}
