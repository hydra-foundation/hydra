<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\NativeHasher;
use PHPUnit\Framework\TestCase;

/**
 * NativeHasher against real password_hash/password_verify: a hash is salted and
 * never the plaintext, empty and malformed stored hashes are rejected rather
 * than matching, and a raised cost is reported as needing a rehash.
 */
final class NativeHasherTest extends TestCase
{
    private NativeHasher $hasher;

    protected function setUp(): void
    {
        // The lowest legal cost keeps the suite fast. The hashing behaviour is
        // identical, only slower, at production cost.
        $this->hasher = new NativeHasher(new AuthConfig(hashCost: 4));
    }

    public function test_hash_does_not_return_the_plaintext(): void
    {
        $hash = $this->hasher->hash('correct horse');

        $this->assertNotSame('correct horse', $hash);
        $this->assertNotSame('', $hash);
    }

    public function test_hash_is_salted_so_two_hashes_of_the_same_password_differ(): void
    {
        // A per-hash random salt means equal passwords still hash differently;
        // this is what makes a stolen hash table useless.
        $this->assertNotSame($this->hasher->hash('pw'), $this->hasher->hash('pw'));
    }

    public function test_verify_accepts_the_right_password(): void
    {
        $hash = $this->hasher->hash('s3cret');

        $this->assertTrue($this->hasher->verify('s3cret', $hash));
    }

    public function test_verify_rejects_the_wrong_password(): void
    {
        $hash = $this->hasher->hash('s3cret');

        $this->assertFalse($this->hasher->verify('guess', $hash));
    }

    public function test_verify_rejects_an_empty_stored_hash(): void
    {
        // The "user has no usable password" case must never authenticate.
        $this->assertFalse($this->hasher->verify('anything', ''));
    }

    public function test_verify_rejects_a_malformed_stored_hash(): void
    {
        $this->assertFalse($this->hasher->verify('anything', 'not-a-bcrypt-hash'));
    }

    public function test_needs_rehash_is_false_for_a_hash_at_the_current_cost(): void
    {
        $hash = $this->hasher->hash('pw');

        $this->assertFalse($this->hasher->needsRehash($hash));
    }

    public function test_needs_rehash_is_true_when_the_cost_increased(): void
    {
        // Hash at cost 4, then raise the policy to 6: the old hash is now weaker
        // than policy and should be upgraded on next login.
        $weak = (new NativeHasher(new AuthConfig(hashCost: 4)))->hash('pw');
        $stronger = new NativeHasher(new AuthConfig(hashCost: 6));

        $this->assertTrue($stronger->needsRehash($weak));
        // And a hash made at the new cost does not need rehashing.
        $this->assertFalse($stronger->needsRehash($stronger->hash('pw')));
    }
}
