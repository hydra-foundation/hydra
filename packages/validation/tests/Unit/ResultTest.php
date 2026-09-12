<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function test_passing_result_exposes_its_validated_data(): void
    {
        $result = new Result([], ['name' => 'Ada']);

        $this->assertTrue($result->passes());
        $this->assertSame(['name' => 'Ada'], $result->validated());
    }

    public function test_empty_result_passes_with_no_validated_data(): void
    {
        $result = new Result;

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->validated());
    }

    public function test_validated_throws_logic_exception_when_result_failed(): void
    {
        // Asking a failed result for validated data is a programming error:
        // fail loud rather than hand out partial data.
        $result = new Result(['name' => 'required'], []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('failed validation result');
        $result->validated();
    }
}
