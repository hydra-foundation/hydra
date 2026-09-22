<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Auth\Testing\UserProviderContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The shipped fake against the contract every application's provider answers,
 * plus the counting it exists for.
 */
#[CoversClass(ArrayUserProvider::class)]
final class ArrayUserProviderTest extends UserProviderContractTestCase
{
    protected function provider(): UserProviderInterface
    {
        // One integer key and one string: the interface allows both, and a
        // provider is the thing that decides which an application uses.
        return (new ArrayUserProvider)
            ->add('admin', new FakeUser(7, 'hash-for-admin'))
            ->add('clerk', new FakeUser('u-8', 'hash-for-clerk'));
    }

    protected function knownUsername(): string
    {
        return 'admin';
    }

    public function test_it_counts_each_kind_of_lookup(): void
    {
        $provider = (new ArrayUserProvider)->add('ada', new FakeUser(1));

        $provider->byUsername('ada');
        $provider->byUsername('nobody');
        $provider->byIdentifier(1);

        $this->assertSame(2, $provider->usernameLookups());
        $this->assertSame(1, $provider->identifierLookups());
    }

    public function test_reset_lookups_zeroes_both_counts(): void
    {
        $provider = (new ArrayUserProvider)->add('ada', new FakeUser(1));
        $provider->byUsername('ada');
        $provider->byIdentifier(1);

        $provider->resetLookups();

        $this->assertSame(0, $provider->usernameLookups());
        $this->assertSame(0, $provider->identifierLookups());
    }
}
