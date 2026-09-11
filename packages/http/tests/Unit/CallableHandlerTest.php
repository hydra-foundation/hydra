<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ArgumentResolver;
use Hydra\Http\CallableHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CallableHandlerTest extends TestCase
{
    private function handler(callable $target, array $params = []): CallableHandler
    {
        return new CallableHandler($target, new ArgumentResolver, $params);
    }

    public function testReturnsTheCallablesResponse(): void
    {
        $expected = $this->createStub(ResponseInterface::class);
        $handler = $this->handler(fn (): ResponseInterface => $expected);

        $response = $handler->handle($this->createStub(ServerRequestInterface::class));

        $this->assertSame($expected, $response);
    }

    public function testPassesTheRequestToTheCallable(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $seen = null;

        $handler = $this->handler(function (ServerRequestInterface $r) use (&$seen): ResponseInterface {
            $seen = $r;
            return $this->createStub(ResponseInterface::class);
        });

        $handler->handle($request);

        $this->assertSame($request, $seen);
    }

    public function testBindsRouteParamsToTypedArguments(): void
    {
        $seen = null;

        $handler = $this->handler(
            function (int $id) use (&$seen): ResponseInterface {
                $seen = $id;
                return $this->createStub(ResponseInterface::class);
            },
            ['id' => '42']
        );

        $handler->handle($this->createStub(ServerRequestInterface::class));

        $this->assertSame(42, $seen);
    }
}
