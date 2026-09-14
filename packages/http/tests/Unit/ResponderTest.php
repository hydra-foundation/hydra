<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Responder;
use Hydra\Http\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
}
