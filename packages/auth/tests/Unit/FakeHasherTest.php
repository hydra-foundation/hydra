<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Testing\FakeHasher;
use Hydra\Auth\Testing\HasherContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(FakeHasher::class)]
final class FakeHasherTest extends HasherContractTestCase
{
    protected function hasher(): HasherInterface
    {
        return new FakeHasher;
    }

    public function test_it_counts_hashes_and_verifications_apart(): void
    {
        $hasher = new FakeHasher;

        $hash = $hasher->hash('pw');
        $hasher->verify('pw', $hash);
        $hasher->verify('pw', 'not-a-hash');

        $this->assertSame(1, $hasher->hashes());
        $this->assertSame(2, $hasher->verifications());
    }

    public function test_reset_zeroes_both_counts(): void
    {
        $hasher = new FakeHasher;
        $hasher->verify('pw', $hasher->hash('pw'));

        $hasher->reset();

        $this->assertSame(0, $hasher->hashes());
        $this->assertSame(0, $hasher->verifications());
    }

    public function test_a_hash_with_no_separator_after_the_salt_is_rejected(): void
    {
        $this->assertFalse((new FakeHasher)->verify('pw', 'fake$abcd'));
    }

    public function test_it_rejects_a_hash_made_by_another_hasher(): void
    {
        $this->assertFalse((new FakeHasher)->verify('pw', password_hash('pw', PASSWORD_DEFAULT, ['cost' => 4])));
    }
}
