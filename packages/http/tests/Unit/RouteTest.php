<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Route;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    private function route(string $path): Route
    {
        return new Route('GET', $path, fn () => null);
    }

    public function testStaticPathMatchesWithNoParams(): void
    {
        $this->assertSame([], $this->route('/health')->matchPath('/health'));
    }

    public function testStaticPathDoesNotMatchDifferentPath(): void
    {
        $this->assertNull($this->route('/health')->matchPath('/status'));
    }

    public function testSingleParamIsExtracted(): void
    {
        $this->assertSame(['id' => '42'], $this->route('/users/{id}')->matchPath('/users/42'));
    }

    public function testMultipleParamsAreExtracted(): void
    {
        $this->assertSame(
            ['post' => '7', 'comment' => '3'],
            $this->route('/posts/{post}/comments/{comment}')->matchPath('/posts/7/comments/3')
        );
    }

    public function testParamDoesNotMatchAcrossASlash(): void
    {
        // A single {id} segment must not swallow an extra path segment.
        $this->assertNull($this->route('/users/{id}')->matchPath('/users/42/edit'));
    }

    public function testParamValueIsUrlDecoded(): void
    {
        $this->assertSame(['name' => 'john doe'], $this->route('/users/{name}')->matchPath('/users/john%20doe'));
    }

    public function testLiteralRegexCharactersAreEscaped(): void
    {
        $route = $this->route('/a.b/{id}');

        // The dot is a literal, not "any character".
        $this->assertSame(['id' => '5'], $route->matchPath('/a.b/5'));
        $this->assertNull($route->matchPath('/aXb/5'));
    }

    public function testDuplicateParamNamesAreRejectedAtConstruction(): void
    {
        // Two groups with the same name would otherwise make PCRE warn and
        // silently never match on every request. Fail fast at registration.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares parameter {id} more than once');

        $this->route('/a/{id}/b/{id}');
    }
}
