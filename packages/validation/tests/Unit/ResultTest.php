<?php

declare(strict_types=1);

namespace Hydra\Validation\Tests\Unit;

use Hydra\Validation\Result;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testPassingResultExposesItsValidatedData(): void
    {
        $result = new Result([], ['name' => 'Ada']);

        $this->assertTrue($result->passes());
        $this->assertSame(['name' => 'Ada'], $result->validated());
    }

    public function testEmptyResultPassesWithNoValidatedData(): void
    {
        $result = new Result;

        $this->assertTrue($result->passes());
        $this->assertSame([], $result->validated());
    }

    public function testValidatedThrowsLogicExceptionWhenResultFailed(): void
    {
        // Asking a failed result for validated data is a programming error:
        // fail loud rather than hand out partial data.
        $result = new Result(['name' => 'required'], []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('failed validation result');
        $result->validated();
    }
}
