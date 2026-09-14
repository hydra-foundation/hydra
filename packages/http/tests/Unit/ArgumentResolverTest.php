<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ArgumentResolver;
use Hydra\Http\Exceptions\NotFoundException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Every way a controller method's parameters can be filled: the request by
 * type, route placeholders coerced to the declared scalar, defaults and
 * nullables, and the wiring errors that are a bug rather than a bad request.
 */
#[CoversClass(ArgumentResolver::class)]
final class ArgumentResolverTest extends TestCase
{
    /**
     * @param array<string, string> $params
     * @return list<mixed>
     */
    private function resolve(callable $target, array $params = [], ?ServerRequestInterface $request = null): array
    {
        $request ??= $this->createStub(ServerRequestInterface::class);

        return (new ArgumentResolver)->resolve($target, $request, $params);
    }

    public function test_empty_signature_resolves_to_no_arguments(): void
    {
        $this->assertSame([], $this->resolve(fn (): string => 'ok'));
    }

    public function test_request_is_injected_by_type(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);

        $args = $this->resolve(fn (ServerRequestInterface $r) => $r, [], $request);

        $this->assertSame([$request], $args);
    }

    public function test_request_is_matched_by_type_regardless_of_name_or_position(): void
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

    public function test_string_placeholder_is_passed_through(): void
    {
        $args = $this->resolve(fn (string $name) => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function test_untyped_placeholder_is_passed_through_as_string(): void
    {
        $args = $this->resolve(fn ($name) => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function test_int_placeholder_is_coerced(): void
    {
        $args = $this->resolve(fn (int $id) => $id, ['id' => '42']);

        $this->assertSame([42], $args);
    }

    public function test_float_placeholder_is_coerced(): void
    {
        $args = $this->resolve(fn (float $ratio) => $ratio, ['ratio' => '3.5']);

        $this->assertSame([3.5], $args);
    }

    #[DataProvider('boolValues')]
    public function test_bool_placeholder_is_coerced(string $raw, bool $expected): void
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

    public function test_bad_int_is_treated_as_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (int $id) => $id, ['id' => 'abc']);
    }

    public function test_bad_float_is_treated_as_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (float $r) => $r, ['r' => 'nope']);
    }

    public function test_unrecognised_bool_is_treated_as_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->resolve(fn (bool $flag) => $flag, ['flag' => 'maybe']);
    }

    public function test_coercion_failure_carries_no_client_facing_message(): void
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

    public function test_default_value_is_used_when_no_placeholder_matches(): void
    {
        $args = $this->resolve(fn (string $name = 'world') => $name, []);

        $this->assertSame(['world'], $args);
    }

    public function test_nullable_parameter_falls_back_to_null(): void
    {
        $args = $this->resolve(fn (?string $name) => $name, []);

        $this->assertSame([null], $args);
    }

    public function test_placeholder_takes_precedence_over_default(): void
    {
        $args = $this->resolve(fn (string $name = 'world') => $name, ['name' => 'ada']);

        $this->assertSame(['ada'], $args);
    }

    public function test_unresolvable_required_parameter_is_a_wiring_error(): void
    {
        $this->expectException(LogicException::class);
        $this->resolve(fn (string $missing) => $missing, []);
    }

    public function test_non_scalar_placeholder_type_is_a_wiring_error(): void
    {
        // An array-typed parameter named like a placeholder can't come from a
        // URL segment: that's a programming mistake, not a client 404.
        $this->expectException(LogicException::class);
        $this->resolve(fn (array $id) => $id, ['id' => '42']);
    }

    public function test_resolves_request_and_placeholders_together(): void
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
