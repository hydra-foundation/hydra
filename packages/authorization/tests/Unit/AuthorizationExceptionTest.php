<?php

declare(strict_types=1);

namespace Hydra\Authorization\Tests\Unit;

use Hydra\Authorization\Exceptions\AuthorizationException;
use Hydra\Http\Exceptions\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The denial is an HttpException carrying a 403, so the error handler renders it
 * like any other status rather than needing to know about authorization.
 */
#[CoversClass(AuthorizationException::class)]
final class AuthorizationExceptionTest extends TestCase
{
    public function test_it_is_a_403_http_exception(): void
    {
        $e = new AuthorizationException;

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(403, $e->status());
    }

    public function test_it_has_a_default_message(): void
    {
        $this->assertSame('This action is unauthorized.', (new AuthorizationException)->getMessage());
    }

    public function test_it_accepts_a_custom_message_and_previous(): void
    {
        $previous = new RuntimeException('root cause');
        $e = new AuthorizationException('Not your note.', $previous);

        $this->assertSame('Not your note.', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }
}
