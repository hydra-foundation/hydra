<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Exceptions\MethodNotAllowedException;
use Hydra\Http\Exceptions\NotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The exception the framework signals an HTTP status with: the status and any
 * headers it carries to the response, and the named constructors for the two
 * cases the router raises itself.
 */
#[CoversClass(HttpException::class)]
final class HttpExceptionTest extends TestCase
{
    public function test_is_a_throwable(): void
    {
        $this->assertInstanceOf(Throwable::class, new HttpException(400));
    }

    public function test_carries_status_and_headers(): void
    {
        $e = new HttpException(418, 'short and stout', ['X-Teapot' => 'yes']);

        $this->assertSame(418, $e->status());
        $this->assertSame(['X-Teapot' => 'yes'], $e->headers());
        $this->assertSame('short and stout', $e->getMessage());
    }

    public function test_defaults_to_no_headers(): void
    {
        $this->assertSame([], (new HttpException(400))->headers());
    }

    public function test_preserves_previous_exception(): void
    {
        $previous = new RuntimeException('root cause');
        $e = new HttpException(500, 'wrapped', [], $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_not_found_is_a_404(): void
    {
        $e = new NotFoundException;

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(404, $e->status());
    }

    public function test_method_not_allowed_is_a_405_with_allow_header(): void
    {
        $e = new MethodNotAllowedException(['GET', 'POST']);

        $this->assertSame(405, $e->status());
        $this->assertSame(['Allow' => 'GET, POST'], $e->headers());
    }

    public function test_method_not_allowed_deduplicates_allowed_methods(): void
    {
        $e = new MethodNotAllowedException(['GET', 'GET', 'HEAD']);

        $this->assertSame(['Allow' => 'GET, HEAD'], $e->headers());
    }
}
