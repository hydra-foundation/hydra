<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Exceptions\BadRequestException;
use Hydra\Http\Query;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    private function query(array $params): Query
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/')->withQueryParams($params);

        return Query::fromRequest($request);
    }

    public function test_string_reads_a_param(): void
    {
        $this->assertSame('ada', $this->query(['q' => 'ada'])->string('q'));
    }

    public function test_string_falls_back_to_default_when_absent(): void
    {
        $query = $this->query([]);

        $this->assertSame('', $query->string('q'));
        $this->assertSame('all', $query->string('q', 'all'));
    }

    public function test_string_defaults_when_param_is_an_array(): void
    {
        // ?q[]=a&q[]=b arrives as an array; stringifying it would be a TypeError.
        $this->assertSame('', $this->query(['q' => ['a', 'b']])->string('q'));
    }

    public function test_int_coerces_numeric_strings(): void
    {
        $this->assertSame(2, $this->query(['page' => '2'])->int('page'));
        $this->assertSame(0, $this->query(['page' => '0'])->int('page'));
    }

    public function test_int_defaults_on_miss_and_non_numeric(): void
    {
        $this->assertNull($this->query([])->int('page'));
        $this->assertSame(1, $this->query([])->int('page', 1));
        $this->assertNull($this->query(['page' => 'first'])->int('page'));
    }

    public function test_float_coerces_numeric_strings(): void
    {
        $this->assertSame(1.5, $this->query(['ratio' => '1.5'])->float('ratio'));
        $this->assertSame(0.0, $this->query(['ratio' => '0'])->float('ratio'));
    }

    public function test_float_defaults_on_miss_and_non_numeric(): void
    {
        $this->assertNull($this->query([])->float('ratio'));
        $this->assertSame(0.5, $this->query([])->float('ratio', 0.5));
        $this->assertNull($this->query(['ratio' => 'half'])->float('ratio'));
    }

    public function test_bool_accepts_explicit_forms(): void
    {
        $this->assertTrue($this->query(['archived' => '1'])->bool('archived'));
        $this->assertTrue($this->query(['archived' => 'yes'])->bool('archived'));
        $this->assertTrue($this->query(['archived' => 'TRUE'])->bool('archived'));
        $this->assertFalse($this->query(['archived' => '0'])->bool('archived'));
        $this->assertFalse($this->query(['archived' => 'off'])->bool('archived'));
        $this->assertFalse($this->query(['archived' => 'No'])->bool('archived'));
    }

    public function test_bool_defaults_when_absent(): void
    {
        $this->assertNull($this->query([])->bool('archived'));
        $this->assertTrue($this->query([])->bool('archived', true));
    }

    public function test_bool_throws_on_garbage(): void
    {
        $this->expectException(BadRequestException::class);

        $this->query(['archived' => 'perhaps'])->bool('archived');
    }

    public function test_array_reads_an_array_param(): void
    {
        $this->assertSame(['a', 'b'], $this->query(['tags' => ['a', 'b']])->array('tags'));
    }

    public function test_array_defaults_when_absent(): void
    {
        $this->assertSame([], $this->query([])->array('tags'));
        $this->assertSame(['x'], $this->query([])->array('tags', ['x']));
    }

    public function test_array_throws_on_scalar(): void
    {
        $this->expectException(BadRequestException::class);

        $this->query(['tags' => 'oops'])->array('tags');
    }

    public function test_has_distinguishes_present_empty_from_absent(): void
    {
        $query = $this->query(['q' => '']);

        $this->assertTrue($query->has('q'));
        $this->assertFalse($query->has('missing'));
    }

    public function test_reads_the_query_string_of_another_url(): void
    {
        $query = Query::fromUrl('https://example.test/admin/users?q=ada&page=3');

        $this->assertSame('ada', $query->string('q'));
        $this->assertSame(3, $query->int('page'));
    }

    public function test_a_url_with_no_query_string_reads_as_empty(): void
    {
        $query = Query::fromUrl('https://example.test/admin/users');

        $this->assertFalse($query->has('page'));
        $this->assertSame('', $query->string('q'));
    }

    public function test_reads_query_params_not_parsed_body(): void
    {
        // The sibling boundary: Query never sees the body, Input never sees
        // the query string.
        $request = (new Psr17Factory)->createServerRequest('POST', '/?page=2')
            ->withQueryParams(['page' => '2'])
            ->withParsedBody(['name' => 'Ada']);

        $query = Query::fromRequest($request);

        $this->assertSame(2, $query->int('page'));
        $this->assertFalse($query->has('name'));
    }
}
