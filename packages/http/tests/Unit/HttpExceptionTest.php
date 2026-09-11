<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\HttpException;
use Hydra\Http\Exceptions\MethodNotAllowedException;
use Hydra\Http\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class HttpExceptionTest extends TestCase
{
    public function testIsAThrowable(): void
    {
        $this->assertInstanceOf(Throwable::class, new HttpException(400));
    }

    public function testCarriesStatusAndHeaders(): void
    {
        $e = new HttpException(418, 'short and stout', ['X-Teapot' => 'yes']);

        $this->assertSame(418, $e->status());
        $this->assertSame(['X-Teapot' => 'yes'], $e->headers());
        $this->assertSame('short and stout', $e->getMessage());
    }

    public function testDefaultsToNoHeaders(): void
    {
        $this->assertSame([], (new HttpException(400))->headers());
    }

    public function testPreservesPreviousException(): void
    {
        $previous = new RuntimeException('root cause');
        $e = new HttpException(500, 'wrapped', [], $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function testNotFoundIsA404(): void
    {
        $e = new NotFoundException;

        $this->assertInstanceOf(HttpException::class, $e);
        $this->assertSame(404, $e->status());
    }

    public function testMethodNotAllowedIsA405WithAllowHeader(): void
    {
        $e = new MethodNotAllowedException(['GET', 'POST']);

        $this->assertSame(405, $e->status());
        $this->assertSame(['Allow' => 'GET, POST'], $e->headers());
    }

    public function testMethodNotAllowedDeduplicatesAllowedMethods(): void
    {
        $e = new MethodNotAllowedException(['GET', 'GET', 'HEAD']);

        $this->assertSame(['Allow' => 'GET, HEAD'], $e->headers());
    }
}
