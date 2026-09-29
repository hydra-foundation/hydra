<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Paginated;
use Hydra\Http\Paging;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The response factory every controller builds through: the content type and
 * status each helper produces, and JSON encoding that neither escapes slashes
 * nor silently emits a broken body.
 */
#[CoversClass(Responder::class)]
final class ResponderTest extends TestCase
{
    private function responder(): Responder
    {
        $psr17 = new Psr17Factory;
        return new Responder($psr17, $psr17);
    }

    public function test_text_response(): void
    {
        $response = $this->responder()->text('hello');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('hello', (string) $response->getBody());
    }

    public function test_html_response(): void
    {
        $response = $this->responder()->html('<h1>hi</h1>', 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<h1>hi</h1>', (string) $response->getBody());
    }

    public function test_accepts_a_status_enum_not_just_an_int(): void
    {
        // The int|Status contract: passing a Status case must normalize to its code.
        $this->assertSame(201, $this->responder()->json([], Status::Created)->getStatusCode());
        $this->assertSame(422, $this->responder()->html('x', Status::UnprocessableEntity)->getStatusCode());
        $this->assertSame(404, $this->responder()->text('x', Status::NotFound)->getStatusCode());
    }

    public function test_json_response(): void
    {
        $response = $this->responder()->json(['name' => 'will', 'roles' => ['admin']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"will","roles":["admin"]}', (string) $response->getBody());
    }

    public function test_json_does_not_escape_slashes_or_unicode(): void
    {
        $response = $this->responder()->json(['url' => 'https://hydra.dev', 'emoji' => '🐍']);

        $this->assertSame('{"url":"https://hydra.dev","emoji":"🐍"}', (string) $response->getBody());
    }

    public function test_json_throws_on_unencodable_data(): void
    {
        $this->expectException(\JsonException::class);

        // A resource cannot be JSON-encoded; we want a thrown error, not false.
        $this->responder()->json(['handle' => fopen('php://memory', 'r')]);
    }

    public function test_no_content_response(): void
    {
        $response = $this->responder()->noContent();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function test_redirect_response(): void
    {
        $response = $this->responder()->redirect('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_download_response(): void
    {
        $response = $this->responder()->download('a,b', 'people.csv', 'text/csv; charset=utf-8');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('3', $response->getHeaderLine('Content-Length'));
        $this->assertSame(
            'attachment; filename="people.csv"; filename*=UTF-8\'\'people.csv',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    /**
     * A download's name is attacker-influenced often enough (a row's title, a
     * module's slug) that the quote and the newline inside it are worth
     * spending a transliteration on. The RFC 5987 form still carries what was
     * asked for, percent-encoded, for the clients that read it.
     */
    public function test_a_download_name_cannot_carry_anything_out_of_the_header(): void
    {
        $disposition = $this->responder()
            ->download('x', "re\"port\r\nX-Evil: 1.csv")
            ->getHeaderLine('Content-Disposition');

        $this->assertSame(
            'attachment; filename="re_port_X-Evil_1.csv"; '
            . 'filename*=UTF-8\'\'re%22port%0D%0AX-Evil%3A%201.csv',
            $disposition,
        );
    }

    public function test_a_download_with_no_usable_name_still_has_one(): void
    {
        $this->assertStringContainsString(
            'filename="download"',
            $this->responder()->download('x', '???')->getHeaderLine('Content-Disposition'),
        );
    }

    public function test_a_page_is_its_items_its_meta_and_its_links(): void
    {
        $response = $this->paginated('/api/posts', ['status' => 'live', 'page' => '2'], total: 57, page: 2, perPage: 20);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame([
            'data' => [['id' => 1], ['id' => 2]],
            'meta' => ['page' => 2, 'per_page' => 20, 'total' => 57, 'pages' => 3],
            'links' => [
                'first' => '/api/posts?status=live&page=1',
                'prev' => '/api/posts?status=live&page=1',
                'next' => '/api/posts?status=live&page=3',
                'last' => '/api/posts?status=live&page=3',
            ],
        ], $this->body($response));
    }

    public function test_the_links_are_also_a_link_header(): void
    {
        $response = $this->paginated('/api/posts', ['page' => '2'], total: 57, page: 2, perPage: 20);

        $this->assertSame(
            '</api/posts?page=1>; rel="first", </api/posts?page=1>; rel="prev", '
            . '</api/posts?page=3>; rel="next", </api/posts?page=3>; rel="last"',
            $response->getHeaderLine('Link'),
        );
    }

    public function test_the_first_page_has_no_prev_and_the_header_leaves_it_out(): void
    {
        $response = $this->paginated('/api/posts', [], total: 57, page: 1, perPage: 20);

        $this->assertNull($this->body($response)['links']['prev']);
        $this->assertSame('/api/posts?page=2', $this->body($response)['links']['next']);
        $this->assertStringNotContainsString('rel="prev"', $response->getHeaderLine('Link'));
    }

    public function test_the_last_page_has_no_next_and_the_header_leaves_it_out(): void
    {
        $response = $this->paginated('/api/posts', ['page' => '3'], total: 57, page: 3, perPage: 20);

        $this->assertNull($this->body($response)['links']['next']);
        $this->assertSame('/api/posts?page=2', $this->body($response)['links']['prev']);
        $this->assertStringNotContainsString('rel="next"', $response->getHeaderLine('Link'));
    }

    public function test_a_single_page_links_only_to_itself(): void
    {
        $response = $this->paginated('/api/posts', [], total: 2, page: 1, perPage: 20);

        $this->assertSame(
            ['first' => '/api/posts?page=1', 'prev' => null, 'next' => null, 'last' => '/api/posts?page=1'],
            $this->body($response)['links'],
        );
        $this->assertSame('</api/posts?page=1>; rel="first", </api/posts?page=1>; rel="last"', $response->getHeaderLine('Link'));
    }

    public function test_past_the_end_is_empty_and_prev_leads_back_to_the_last_page(): void
    {
        $response = $this->paginated('/api/posts', ['page' => '7'], total: 57, page: 7, perPage: 20, items: []);
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['data']);
        $this->assertSame('/api/posts?page=3', $body['links']['prev']);
        $this->assertSame($body['links']['last'], $body['links']['prev']);
        $this->assertNull($body['links']['next']);
    }

    public function test_per_page_is_carried_only_when_the_client_sent_it(): void
    {
        $sent = $this->paginated('/api/posts', ['per_page' => '5'], total: 12, page: 1, perPage: 5);
        $unsent = $this->paginated('/api/posts', [], total: 12, page: 1, perPage: 5);

        $this->assertSame('/api/posts?per_page=5&page=2', $this->body($sent)['links']['next']);
        $this->assertSame('/api/posts?page=2', $this->body($unsent)['links']['next']);
    }

    public function test_a_clamped_per_page_is_written_into_the_links_as_clamped(): void
    {
        // Asked for 5000 and paged by 100: each link has to describe the page
        // it leads to, and agree with meta, rather than repeat the request.
        $response = $this->paginated('/api/posts', ['per_page' => '5000'], total: 250, page: 1, perPage: 100);
        $body = $this->body($response);

        $this->assertSame(100, $body['meta']['per_page']);
        $this->assertSame('/api/posts?per_page=100&page=2', $body['links']['next']);
        $this->assertStringNotContainsString('5000', $response->getHeaderLine('Link'));
    }

    public function test_array_parameters_survive_into_the_links(): void
    {
        $response = $this->paginated('/api/posts', ['tag' => ['a', 'b']], total: 50, page: 1, perPage: 20);

        $next = $this->body($response)['links']['next'];
        parse_str((string) parse_url($next, PHP_URL_QUERY), $params);

        $this->assertSame('/api/posts', parse_url($next, PHP_URL_PATH));
        $this->assertSame(['tag' => ['a', 'b'], 'page' => '2'], $params);
    }

    public function test_the_links_never_name_a_host(): void
    {
        // Relative on purpose: an absolute link would have to trust the Host
        // header, and a forged one would be handed back in every link.
        $request = (new Psr17Factory)
            ->createServerRequest('GET', 'http://evil.example/api/posts?page=1')
            ->withQueryParams(['page' => '1']);

        $response = $this->responder()->paginated(new Paginated([], 50, new Paging(1, 20)), $request);

        $this->assertStringNotContainsString('evil.example', (string) $response->getBody());
        $this->assertStringNotContainsString('evil.example', $response->getHeaderLine('Link'));
    }

    public function test_the_items_are_encoded_like_json(): void
    {
        $response = $this->paginated('/', [], total: 1, page: 1, perPage: 20, items: [['url' => 'https://x.test/a', 'name' => 'café']]);

        $this->assertStringContainsString('{"url":"https://x.test/a","name":"café"}', (string) $response->getBody());
        $this->assertSame('/?page=1', $this->body($response)['links']['first']);
    }

    /**
     * @param array<string, mixed> $query
     * @param list<array<string, mixed>>|null $items
     */
    private function paginated(string $path, array $query, int $total, int $page, int $perPage, ?array $items = null): ResponseInterface
    {
        $request = (new Psr17Factory)
            ->createServerRequest('GET', $path . ($query === [] ? '' : '?' . http_build_query($query)))
            ->withQueryParams($query);

        return $this->responder()->paginated(
            new Paginated($items ?? [['id' => 1], ['id' => 2]], $total, new Paging($page, $perPage)),
            $request,
        );
    }

    /** @return array<string, mixed> */
    private function body(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);

        return $body;
    }
}
