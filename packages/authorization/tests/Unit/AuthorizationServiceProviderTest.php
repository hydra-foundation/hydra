<?php

declare(strict_types=1);

namespace Hydra\Authorization\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Authorization\Contracts\AbilityInterface;
use Hydra\Authorization\Contracts\GateInterface;
use Hydra\Authorization\Gate;
use Hydra\Core\Testing\FakeContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The wiring. A gate holding a different guard than the one the middleware
 * authenticated against answers every question about nobody, which denies a
 * signed-in user rather than failing, and so reads as a permissions bug.
 */
#[CoversClass(AuthorizationServiceProvider::class)]
final class AuthorizationServiceProviderTest extends TestCase
{
    public function test_it_binds_the_shipped_gate_behind_the_interface(): void
    {
        $container = $this->register(FakeGuard::guest());

        $this->assertInstanceOf(Gate::class, $container->get(GateInterface::class));
    }

    public function test_the_gate_is_shared_for_the_request(): void
    {
        $container = $this->register(FakeGuard::guest());

        $this->assertSame(
            $container->get(GateInterface::class),
            $container->get(GateInterface::class),
        );
    }

    public function test_the_gate_asks_the_container_the_provider_was_given(): void
    {
        // Abilities are resolved by class-string at call time, so a gate built
        // against a different container cannot find any the application bound.
        $container = $this->register(FakeGuard::guest());
        $container->instance(AllowEverything::class, new AllowEverything);

        $this->assertTrue($container->get(GateInterface::class)->allows(AllowEverything::class));
    }

    public function test_the_gate_reads_the_guard_bound_at_the_time_it_is_built(): void
    {
        $guard = FakeGuard::guest();
        $container = $this->register($guard);
        $container->instance(NamesTheUser::class, new NamesTheUser);

        $guard->login(new FakeUser('ada'));

        $this->assertTrue($container->get(GateInterface::class)->allows(NamesTheUser::class, 'ada'));
    }

    public function test_nothing_is_built_until_the_gate_is_asked_for(): void
    {
        $container = $this->register(FakeGuard::guest());

        $this->assertFalse($container->isResolved(GateInterface::class));
    }

    private function register(GuardInterface $guard): FakeContainer
    {
        $container = new FakeContainer([GuardInterface::class => $guard]);
        (new AuthorizationServiceProvider)->register($container);

        return $container;
    }
}

final class AllowEverything implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return true;
    }
}

/** Granted only when the subject is the signed-in user's own identifier. */
final class NamesTheUser implements AbilityInterface
{
    public function authorize(?AuthenticatableInterface $user, mixed $subject = null): bool
    {
        return $user !== null && $user->getAuthIdentifier() === $subject;
    }
}
