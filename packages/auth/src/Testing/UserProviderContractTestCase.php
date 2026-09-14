<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every user provider owes the guard, published because this is
 * the one seam the framework ships and never fills: auth owns no user storage,
 * so every application writes this implementation itself, against its own
 * schema, with nothing to check it against.
 *
 * What the guard assumes, and cannot verify at the call site, is that a lookup
 * is an exact match on one field. A provider that matches loosely — a LIKE, a
 * username interpolated into the statement, an id cast until it matches
 * something — hands the guard the wrong user, and the guard then verifies a
 * password against that user's hash and lets the caller in as them.
 */
abstract class UserProviderContractTestCase extends TestCase
{
    /** A provider that can find the user named by {@see knownUsername()}. */
    abstract protected function provider(): UserProviderInterface;

    /**
     * The username of a user the provider knows about. Long enough that a
     * character can be taken off it and still leave a name, since that is how
     * the exact-match case asks for one that should not be found.
     */
    abstract protected function knownUsername(): string;

    private function known(): AuthenticatableInterface
    {
        $user = $this->provider()->byUsername($this->knownUsername());

        $this->assertNotNull($user, 'The provider does not know its own known username.');

        return $user;
    }

    public function test_it_finds_a_user_by_username(): void
    {
        $this->assertInstanceOf(AuthenticatableInterface::class, $this->known());
    }

    public function test_the_identifier_it_returns_finds_the_same_user_again(): void
    {
        // The round trip the guard actually performs: it keeps the identifier in
        // the session at login and restores the user from it on every request
        // after. A provider whose two lookups disagree logs people in as
        // somebody else one request later.
        $user = $this->known();
        $restored = $this->provider()->byIdentifier($user->getAuthIdentifier());

        $this->assertNotNull($restored);
        $this->assertSame($user->getAuthIdentifier(), $restored->getAuthIdentifier());
    }

    public function test_it_hands_back_a_password_hash_to_verify_against(): void
    {
        // Not a check that it is hashed — the provider does not hash anything —
        // only that the field the guard verifies against is populated. An empty
        // string is the documented "no usable password" value, and a provider
        // returning it for a user who has one locks that user out.
        $this->assertNotSame('', $this->known()->getAuthPassword());
    }

    public function test_an_unknown_username_is_a_miss_not_an_error(): void
    {
        // null, not an exception: a failed login is the ordinary case, and a
        // provider that threw would turn every typo into a 500.
        $this->assertNull($this->provider()->byUsername('nobody-by-that-name'));
    }

    public function test_an_unknown_identifier_is_a_miss_not_an_error(): void
    {
        $this->assertNull($this->provider()->byIdentifier('nobody-by-that-id'));
    }

    public function test_a_username_lookup_is_an_exact_match(): void
    {
        $username = $this->knownUsername();

        $this->assertNull($this->provider()->byUsername(substr($username, 0, -1)));
        $this->assertNull($this->provider()->byUsername($username . 'x'));
    }

    /** @return array<string, array{string}> */
    public static function usernamesThatMatchNobody(): array
    {
        return [
            'empty' => [''],
            'sql wildcard' => ['%'],
            'sql wildcards around a real name' => ['%a%'],
            'single character wildcard' => ['_'],
            'a tautology' => ["' OR '1'='1"],
            'a comment' => ["admin'--"],
        ];
    }

    #[DataProvider('usernamesThatMatchNobody')]
    public function test_no_submitted_username_can_match_a_user_it_is_not(string $username): void
    {
        // Straight from a login form, so every one of these is a string the
        // provider will really be handed. A wildcard reaching a LIKE, or any of
        // these reaching the statement text, returns somebody.
        $this->assertNull($this->provider()->byUsername($username));
    }
}
