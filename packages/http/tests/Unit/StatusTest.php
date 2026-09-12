<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function test_cases_carry_their_code(): void
    {
        $this->assertSame(200, Status::Ok->value);
        $this->assertSame(422, Status::UnprocessableEntity->value);
        $this->assertSame(404, Status::NotFound->value);
    }

    public function test_reason_phrase(): void
    {
        $this->assertSame('OK', Status::Ok->reason());
        $this->assertSame('Unprocessable Entity', Status::UnprocessableEntity->reason());
        $this->assertSame('Internal Server Error', Status::InternalServerError->reason());
    }

    public function test_reason_for_known_code(): void
    {
        $this->assertSame('Not Found', Status::reasonFor(404));
        $this->assertSame('Service Unavailable', Status::reasonFor(503));
    }

    public function test_reason_for_unknown_code_is_null(): void
    {
        $this->assertNull(Status::reasonFor(418));
        $this->assertNull(Status::reasonFor(299));
    }

    public function test_every_case_has_a_reason_phrase(): void
    {
        // reason() is a match with no default arm: adding a case without an arm
        // throws UnhandledMatchError. This locks every case to a non-empty phrase.
        foreach (Status::cases() as $status) {
            $this->assertNotSame('', $status->reason(), "{$status->name} has no reason phrase");
        }
    }
}
