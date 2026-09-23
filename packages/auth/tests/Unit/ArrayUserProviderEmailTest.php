<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\EmailUserProviderInterface;
use Hydra\Auth\Testing\ArrayUserProvider;
use Hydra\Auth\Testing\EmailUserProviderContractTestCase;
use Hydra\Auth\Testing\FakeUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ArrayUserProvider::class)]
final class ArrayUserProviderEmailTest extends EmailUserProviderContractTestCase
{
    protected function emailProvider(): EmailUserProviderInterface
    {
        return (new ArrayUserProvider)
            ->add('admin', new FakeUser(7, 'hash', 'admin@example.com'))
            ->add('clerk', new FakeUser('u-8', 'hash', 'clerk@example.com'));
    }

    protected function knownEmail(): string
    {
        return 'admin@example.com';
    }

    public function test_it_finds_the_user_at_that_address_and_no_other(): void
    {
        $this->assertSame('u-8', $this->emailProvider()->byEmail('clerk@example.com')?->getAuthIdentifier());
    }

    public function test_case_does_not_matter(): void
    {
        $this->assertSame(7, $this->emailProvider()->byEmail('Admin@Example.COM')?->getAuthIdentifier());
    }

    public function test_a_user_without_an_address_is_never_found(): void
    {
        $provider = (new ArrayUserProvider)->add('bare', new class implements AuthenticatableInterface {
            public function getAuthIdentifier(): int
            {
                return 1;
            }

            public function getAuthPassword(): string
            {
                return 'hash';
            }
        });

        $this->assertNull($provider->byEmail('bare'));
    }
}
