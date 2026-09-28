<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Bytes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bytes::class)]
final class BytesTest extends TestCase
{
    #[DataProvider('sizes')]
    public function test_a_size_reads_in_the_largest_unit_it_fills(int $bytes, string $expected): void
    {
        $this->assertSame($expected, Bytes::human($bytes));
    }

    /** @return iterable<string, array{int, string}> */
    public static function sizes(): iterable
    {
        yield 'nothing' => [0, '0 B'];
        yield 'one byte' => [1, '1 B'];
        yield 'just under a kilobyte' => [1023, '1023 B'];
        yield 'a kilobyte' => [1024, '1 KB'];
        yield 'a fraction of a kilobyte' => [13_642, '13.3 KB'];
        yield 'half a megabyte' => [524_288, '512 KB'];
        yield 'just under a megabyte rounds within its unit' => [1_048_575, '1024 KB'];
        yield 'a megabyte' => [1_048_576, '1 MB'];
        yield 'a megabyte and a half' => [1_572_864, '1.5 MB'];
        yield 'a gigabyte' => [1_073_741_824, '1 GB'];
        yield 'many gigabytes' => [5_000_000_000, '4.7 GB'];
    }
}
