<?php

declare(strict_types=1);

namespace Hydra\Auth\Testing;

use Hydra\Auth\Contracts\HasherInterface;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every hasher owes its callers, published so an application that
 * replaces the shipped one has something to run its replacement against.
 *
 * Nothing else in the framework fails as quietly as a wrong hasher: a verify()
 * that returns true too often authenticates the wrong person, and one that
 * returns false for its own hash locks everybody out, and neither shows up in
 * any test an application writes about its own controllers. Whatever the
 * algorithm, these are the properties auth depends on.
 */
abstract class HasherContractTestCase extends TestCase
{
    abstract protected function hasher(): HasherInterface;

    public function test_hash_does_not_return_the_plaintext(): void
    {
        $hash = $this->hasher()->hash('correct horse');

        $this->assertNotSame('correct horse', $hash);
        $this->assertNotSame('', $hash);
    }

    public function test_hash_is_salted_so_two_hashes_of_the_same_password_differ(): void
    {
        // A per-hash random salt means equal passwords still hash differently;
        // this is what makes a stolen hash table useless.
        $hasher = $this->hasher();

        $this->assertNotSame($hasher->hash('pw'), $hasher->hash('pw'));
    }

    public function test_verify_accepts_the_right_password(): void
    {
        $hasher = $this->hasher();

        $this->assertTrue($hasher->verify('s3cret', $hasher->hash('s3cret')));
    }

    public function test_verify_rejects_the_wrong_password(): void
    {
        $hasher = $this->hasher();

        $this->assertFalse($hasher->verify('guess', $hasher->hash('s3cret')));
    }

    public function test_verify_rejects_a_prefix_of_the_right_password(): void
    {
        // A comparison that stopped at the shorter string's end would accept
        // this, and an attacker can always supply the shorter string.
        $hasher = $this->hasher();

        $this->assertFalse($hasher->verify('s3c', $hasher->hash('s3cret')));
    }

    public function test_verify_rejects_an_empty_stored_hash(): void
    {
        // The "user has no usable password" case must never authenticate. It is
        // what a nullable column reads back as, so it is not hypothetical.
        $this->assertFalse($this->hasher()->verify('anything', ''));
    }

    public function test_verify_rejects_a_malformed_stored_hash(): void
    {
        $this->assertFalse($this->hasher()->verify('anything', 'not-a-hash'));
    }

    public function test_an_empty_password_hashes_and_verifies_as_itself(): void
    {
        // Refusing to hash '' is a policy an application may hold, but it holds
        // it in validation. A hasher handed one must not throw, and must not
        // then match every other password.
        $hasher = $this->hasher();
        $hash = $hasher->hash('');

        $this->assertTrue($hasher->verify('', $hash));
        $this->assertFalse($hasher->verify('x', $hash));
    }

    public function test_a_password_survives_bytes_that_are_not_ascii(): void
    {
        // Whatever a passphrase field accepts has to round-trip. A NUL byte is
        // deliberately not asked for: bcrypt cannot carry one, so refusing it is
        // a limit an implementation is allowed to have, and NativeHasherTest
        // pins what refusing looks like.
        $hasher = $this->hasher();
        $password = "pässwörd \u{1F510} \n\t ok";

        $this->assertTrue($hasher->verify($password, $hasher->hash($password)));
    }

    public function test_a_fresh_hash_does_not_need_rehashing(): void
    {
        // The upgrade-on-login path reads this to decide whether to re-store a
        // password. A hasher that always says yes rewrites the row on every
        // single login; one that always says no can never roll a cost forward.
        $hasher = $this->hasher();

        $this->assertFalse($hasher->needsRehash($hasher->hash('pw')));
    }
}
