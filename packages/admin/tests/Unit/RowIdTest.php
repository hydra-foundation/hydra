<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\RowId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RowId::class)]
final class RowIdTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function keys(): array
    {
        return [
            'one' => ['1', 1],
            'many digits' => ['4096', 4096],
            'zero' => ['0', 0],
            'negative' => ['-3', -3],
        ];
    }

    #[DataProvider('keys')]
    public function test_a_canonical_integer_is_the_key_it_spells(string $id, int $expected): void
    {
        $this->assertSame($expected, RowId::int($id));
    }

    /** @return array<string, array{string}> */
    public static function nonKeys(): array
    {
        return [
            'empty' => [''],
            'a suffix' => ['1-not-an-id'],
            'a prefix' => ['id-1'],
            'leading zero' => ['01'],
            'a leading plus' => ['+1'],
            'leading space' => [' 1'],
            'trailing tab' => ["1\t"],
            'a decimal' => ['1.0'],
            'a wildcard' => ['1%'],
            'a tautology' => ["1' OR '1'='1"],
            'hexadecimal' => ['0x1'],
            'scientific' => ['1e2'],
            'letters' => ['abc'],
        ];
    }

    #[DataProvider('nonKeys')]
    public function test_anything_else_is_refused_rather_than_coerced(string $id): void
    {
        // Every one of these is a string (int) would read as a number, which is
        // the whole reason this exists rather than the cast.
        $this->assertNull(RowId::int($id));
    }
}
