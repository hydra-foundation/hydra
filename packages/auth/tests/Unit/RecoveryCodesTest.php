<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Auth\NativeHasher;
use Hydra\Auth\RecoveryCodes;
use Hydra\Auth\Testing\FakeHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoveryCodes::class)]
final class RecoveryCodesTest extends TestCase
{
    private FakeHasher $hasher;

    private RecoveryCodes $codes;

    protected function setUp(): void
    {
        $this->hasher = new FakeHasher;
        $this->codes = new RecoveryCodes($this->hasher);
    }

    public function test_ten_codes_of_two_groups_of_five_from_the_readable_alphabet(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate();

        $this->assertCount(10, $codes);
        $this->assertCount(10, $hashes);
        $this->assertCount(10, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[2-9a-hjkmnp-z]{5}-[2-9a-hjkmnp-z]{5}$/', $code);
        }
    }

    public function test_every_character_is_drawn_evenly(): void
    {
        // 6,000 draws over 31 characters is about 194 each, with a standard
        // deviation near 14. The bounds sit six deviations out, so this does
        // not flake, and still fail a character that never comes up or one
        // drawn twice as often.
        $counts = count_chars(str_replace('-', '', implode('', $this->codes->generate(600)['codes'])), 1);

        $this->assertCount(31, $counts, 'every character of the alphabet appears');

        foreach ($counts as $char => $count) {
            $this->assertGreaterThan(110, $count, chr($char) . ' is drawn too rarely');
            $this->assertLessThan(280, $count, chr($char) . ' is drawn too often');
        }
    }

    public function test_the_two_groups_are_ten_separate_characters(): void
    {
        $codes = $this->codes->generate()['codes'];

        // The two groups share a character, the fifth and the sixth, in all
        // ten codes about once in 31^10 runs; every time when a group overlaps.
        $this->assertNotSame(
            array_map(static fn (string $c): string => $c[4], $codes),
            array_map(static fn (string $c): string => $c[6], $codes),
        );
    }

    public function test_only_hashes_are_handed_over_for_storage(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate();

        foreach ($hashes as $i => $hash) {
            $this->assertNotSame($codes[$i], $hash);
            $this->assertTrue($this->hasher->verify(str_replace('-', '', $codes[$i]), $hash));
        }
    }

    public function test_the_count_can_be_changed(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate(3);

        $this->assertCount(3, $codes);
        $this->assertCount(3, $hashes);
        $this->assertSame([], $this->codes->generate(0)['codes']);
    }

    public function test_a_code_redeems_once_and_leaves_the_rest(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate(3);

        $left = $this->codes->redeem($codes[1], $hashes);

        $this->assertSame([$hashes[0], $hashes[2]], $left);
        $this->assertNull($this->codes->redeem($codes[1], $left));
        $this->assertSame([$hashes[2]], $this->codes->redeem($codes[0], $left));
    }

    public function test_the_last_code_leaves_an_empty_list_not_null(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate(1);

        $this->assertSame([], $this->codes->redeem($codes[0], $hashes));
    }

    /** @return iterable<string, array{callable(string): string}> */
    public static function writtenBack(): iterable
    {
        yield 'upper case' => [strtoupper(...)];
        yield 'without the dash' => [static fn (string $c): string => str_replace('-', '', $c)];
        yield 'a space for the dash' => [static fn (string $c): string => str_replace('-', ' ', $c)];
        yield 'spaced out' => [static fn (string $c): string => implode(' ', str_split(str_replace('-', '', $c)))];
    }

    /** @param callable(string): string $write */
    #[DataProvider('writtenBack')]
    public function test_a_code_reads_however_it_was_written_back(callable $write): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate(1);

        $this->assertSame([], $this->codes->redeem($write($codes[0]), $hashes));
    }

    public function test_a_wrong_code_is_null_and_spends_nothing(): void
    {
        ['hashes' => $hashes] = $this->codes->generate(3);

        $this->assertNull($this->codes->redeem('22222-22222', $hashes));
        $this->assertNull($this->codes->redeem('anything', []));
    }

    public function test_a_code_of_the_wrong_length_costs_no_hash(): void
    {
        ['hashes' => $hashes] = $this->codes->generate(3);
        $this->hasher->reset();

        $this->assertNull($this->codes->redeem('', $hashes));
        $this->assertNull($this->codes->redeem('abcd-efgh', $hashes));
        $this->assertNull($this->codes->redeem('abcde-fghjk-m', $hashes));
        $this->assertSame(0, $this->hasher->verifications());
    }

    public function test_it_stops_at_the_first_match(): void
    {
        ['codes' => $codes, 'hashes' => $hashes] = $this->codes->generate(5);
        $this->hasher->reset();

        $this->codes->redeem($codes[1], $hashes);

        $this->assertSame(2, $this->hasher->verifications());
    }

    public function test_it_works_with_the_real_hasher(): void
    {
        $codes = new RecoveryCodes(new NativeHasher(new AuthConfig(hashCost: 4)));
        ['codes' => $plain, 'hashes' => $hashes] = $codes->generate(2);

        $this->assertStringStartsWith('$2y$04$', $hashes[0]);
        $this->assertSame([$hashes[0]], $codes->redeem(strtoupper($plain[1]), $hashes));
    }
}
