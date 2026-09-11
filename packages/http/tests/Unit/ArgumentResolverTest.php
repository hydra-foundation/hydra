<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ArgumentResolver;
use Hydra\Http\Exceptions\NotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ArgumentResolverTest extends TestCase
{
    private function resolve(callable $target, array $params = [], ?ServerRequestInterface $request = null): array
    {
        $request ??= $this->createStub(ServerRequestInterface::class);

        return (new ArgumentResolver)->resolve($target, $request, $params);
    }

    public function testEmptySignatureResolvesToNoArguments(): void
    {
        $this->assertSame([], $this->resolve(fn (): string => 'ok'));
    }

    public function testRequestIsInjectedByType(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $args = $this->resolve(fn (ServerRequestInterface $r) => $r, [], $request);

        $this->assertSame([$request], $args);
    }

    public function testRequestIsMatchedByTypeRegardlessOfNameOrPosition(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        // Named "id" (a placeholder name) but typed as the request: type wins.
        $args = $this->resolve(
            fn (string $name, ServerRequestInterface $id) => null,
            ['name' => 'ada'],
            $request
        );

        $this->assertSame(['ada', $request], $args);
    }

    public function testStringPlaceholderIsPassedThrough(): void
    {
        $args = $this->resolve(fn (string $name) => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function testUntypedPlaceholderIsPassedThroughAsString(): void
    {
        $args = $this->resolve(fn ($name) => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function testIntPlaceholderIsCoerced(): void
    {
        $args = $this->resolve(fn (int $id) => $id, ['id' => '42']);

        $this->assertSame([42], $args);
    }

    public function testFloatPlaceholderIsCoerced(): void
    {
        $args = $this->resolve(fn (float $ratio) => $ratio, ['ratio' => '3.5']);

        $this->assertSame([3.5], $args);
    }

    #[DataProvider('boolValues')]
    public function testBoolPlaceholderIsCoerced(string $raw, bool $expected): void
    {
        $args = $this->resolve(fn (bool $flag) => $flag, ['flag' => $raw]);

        $this->assertSame([$expected], $args);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function boolValues(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'True (case-insensitive)' => ['True', true];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
    }

    public function testBadIntIsTreatedAsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (int $id) => $id, ['id' => 'abc']);
    }

    public function testBadFloatIsTreatedAsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (float $r) => $r, ['r' => 'nope']);
    }

    public function testUnrecognisedBoolIsTreatedAsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (bool $flag) => $flag, ['flag' => 'maybe']);
    }

    public function testCoercionFailureCarriesNoClientFacingMessage(): void
    {
        try {
            $this->resolve(fn (int $id) => $id, ['id' => 'abc']);
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            // Empty so ErrorHandlerMiddleware shows the bare reason phrase and
            // leaks nothing about the route signature.
            $this->assertSame('', $e->getMessage());
            $this->assertSame(404, $e->status());
        }
    }

    public function testDefaultValueIsUsedWhenNoPlaceholderMatches(): void
    {
        $args = $this->resolve(fn (string $name = 'world') => $name, []);

        $this->assertSame(['world'], $args);
    }

    public function testNullableParameterFallsBackToNull(): void
    {
        $args = $this->resolve(fn (?string $name) => $name, []);

        $this->assertSame([null], $args);
    }

    public function testPlaceholderTakesPrecedenceOverDefault(): void
    {
        $args = $this->resolve(fn (string $name = 'world') => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function testUnresolvableRequiredParameterIsAWiringError(): void
    {
        $this->expectException(LogicException::class);
        $this->resolve(fn (string $missing) => $missing, []);
    }

    public function testNonScalarPlaceholderTypeIsAWiringError(): void
    {
        // An array-typed parameter named like a placeholder can't come from a
        // URL segment: that's a programming mistake, not a client 404.
        $this->expectException(LogicException::class);
        $this->resolve(fn (array $id) => $id, ['id' => '42']);
    }

    public function testResolvesRequestAndPlaceholdersTogether(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $args = $this->resolve(
            fn (ServerRequestInterface $r, int $id, string $slug) => null,
            ['id' => '7', 'slug' => 'hello'],
            $request
        );

        $this->assertSame([$request, 7, 'hello'], $args);
    }
}
