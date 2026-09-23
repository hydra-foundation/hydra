<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\EmailUserProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a password reset assumes of lookup by address: an exact match on one
 * field, the same as {@see UserProviderContractTestCase} asks of usernames.
 * A provider that matches loosely mails a reset link for one account to
 * whoever typed a wildcard. Case is left to the provider.
 */
abstract class EmailUserProviderContractTestCase extends TestCase
{
    /** A provider that can find the user at {@see knownEmail()}. */
    abstract protected function emailProvider(): EmailUserProviderInterface;

    abstract protected function knownEmail(): string;

    public function test_it_finds_a_user_by_email(): void
    {
        $this->assertNotNull($this->emailProvider()->byEmail($this->knownEmail()));
    }

    public function test_an_unknown_address_is_a_miss_not_an_error(): void
    {
        $this->assertNull($this->emailProvider()->byEmail('nobody@nowhere.invalid'));
    }

    public function test_an_email_lookup_is_an_exact_match(): void
    {
        $email = $this->knownEmail();

        $this->assertNull($this->emailProvider()->byEmail(substr($email, 1)));
        $this->assertNull($this->emailProvider()->byEmail($email . 'x'));
    }

    /** @return array<string, array{string}> */
    public static function addressesThatMatchNobody(): array
    {
        return [
            'empty' => [''],
            'sql wildcard' => ['%'],
            'wildcard domain' => ['%@%'],
            'single character wildcard' => ['_'],
            'a tautology' => ["' OR '1'='1"],
        ];
    }

    #[DataProvider('addressesThatMatchNobody')]
    public function test_no_submitted_address_can_match_a_user_it_is_not(string $email): void
    {
        $this->assertNull($this->emailProvider()->byEmail($email));
    }
}
