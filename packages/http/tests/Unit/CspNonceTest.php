<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\CspNonce;
use PHPUnit\Framework\TestCase;

final class CspNonceTest extends TestCase
{
    public function test_returns_the_same_token_every_time_it_is_read(): void
    {
        $nonce = new CspNonce;

        $this->assertSame($nonce->value(), $nonce->value());
    }

    public function test_mints_a_different_token_per_instance(): void
    {
        $this->assertNotSame((new CspNonce)->value(), (new CspNonce)->value());
    }

    public function test_the_token_needs_no_escaping_in_a_header_or_an_attribute(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (new CspNonce)->value());
    }
}
