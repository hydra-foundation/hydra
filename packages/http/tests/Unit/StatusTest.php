<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    public function testCasesCarryTheirCode(): void
    {
        $this->assertSame(200, Status::Ok->value);
        $this->assertSame(422, Status::UnprocessableEntity->value);
        $this->assertSame(404, Status::NotFound->value);
    }

    public function testReasonPhrase(): void
    {
        $this->assertSame('OK', Status::Ok->reason());
        $this->assertSame('Unprocessable Entity', Status::UnprocessableEntity->reason());
        $this->assertSame('Internal Server Error', Status::InternalServerError->reason());
    }

    public function testReasonForKnownCode(): void
    {
        $this->assertSame('Not Found', Status::reasonFor(404));
        $this->assertSame('Service Unavailable', Status::reasonFor(503));
    }

    public function testReasonForUnknownCodeIsNull(): void
    {
        $this->assertNull(Status::reasonFor(418));
        $this->assertNull(Status::reasonFor(299));
    }

    public function testEveryCaseHasAReasonPhrase(): void
    {
        // reason() is a match with no default arm: adding a case without an arm
        // throws UnhandledMatchError. This locks every case to a non-empty phrase.
        foreach (Status::cases() as $status) {
            $this->assertNotSame('', $status->reason(), "{$status->name} has no reason phrase");
        }
    }
}
