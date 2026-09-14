<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\Testing\HasherContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ValueError;

/**
 * NativeHasher against the shared contract, plus the one thing that is its own:
 * bcrypt carries its work factor inside the hash, so a policy that raises the
 * cost can be read back off hashes made under the old one.
 */
#[CoversClass(NativeHasher::class)]
final class NativeHasherTest extends HasherContractTestCase
{
    protected function hasher(): HasherInterface
    {
        // The lowest legal cost keeps the suite fast. The hashing behaviour is
        // identical, only slower, at production cost.
        return new NativeHasher(new AuthConfig(hashCost: 4));
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

    public function test_a_password_containing_a_null_byte_is_refused_loudly(): void
    {
        // bcrypt truncates at the first NUL, so password_hash refuses one rather
        // than silently hashing the prefix. Nothing catches this on the way up:
        // a registration form that lets a NUL through reaches here and 500s, so
        // keeping one out is the application's validation to write.
        $this->expectException(ValueError::class);
        $this->hasher()->hash("pass\0word");
    }
}
