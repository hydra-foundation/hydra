<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\CompiledRoute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Path compilation and matching: placeholders capture within one segment,
 * literal regex characters in a path stay literal, and captured values are
 * url-decoded.
 */
#[CoversClass(CompiledRoute::class)]
final class CompiledRouteTest extends TestCase
{
    private function route(string $path): CompiledRoute
    {
        return new CompiledRoute('GET', $path, fn () => null);
    }

    public function test_static_path_matches_with_no_params(): void
    {
        $this->assertSame([], $this->route('/health')->matchPath('/health'));
    }

    public function test_static_path_does_not_match_different_path(): void
    {
        $this->assertNull($this->route('/health')->matchPath('/status'));
    }

    public function test_single_param_is_extracted(): void
    {
        $this->assertSame(['id' => '42'], $this->route('/users/{id}')->matchPath('/users/42'));
    }

    public function test_multiple_params_are_extracted(): void
    {
        $this->assertSame(
            ['post' => '7', 'comment' => '3'],
            $this->route('/posts/{post}/comments/{comment}')->matchPath('/posts/7/comments/3')
        );
    }

    public function test_param_does_not_match_across_a_slash(): void
    {
        // A single {id} segment must not swallow an extra path segment.
        $this->assertNull($this->route('/users/{id}')->matchPath('/users/42/edit'));
    }

    public function test_param_value_is_url_decoded(): void
    {
        $this->assertSame(['name' => 'john doe'], $this->route('/users/{name}')->matchPath('/users/john%20doe'));
    }

    public function test_literal_regex_characters_are_escaped(): void
    {
        $route = $this->route('/a.b/{id}');

        // The dot is a literal, not "any character".
        $this->assertSame(['id' => '5'], $route->matchPath('/a.b/5'));
        $this->assertNull($route->matchPath('/aXb/5'));
    }

    public function test_duplicate_param_names_are_rejected_at_construction(): void
    {
        // Two groups with the same name would otherwise make PCRE warn and
        // silently never match on every request. Fail fast at registration.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('declares parameter {id} more than once');

        $this->route('/a/{id}/b/{id}');
    }
}
