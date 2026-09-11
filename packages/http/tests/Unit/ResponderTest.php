<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Responder;
use Hydra\Http\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ResponderTest extends TestCase
{
    private function responder(): Responder
    {
        $psr17 = new Psr17Factory;
        return new Responder($psr17, $psr17);
    }

    public function testTextResponse(): void
    {
        $response = $this->responder()->text('hello');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('hello', (string) $response->getBody());
    }

    public function testHtmlResponse(): void
    {
        $response = $this->responder()->html('<h1>hi</h1>', 201);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<h1>hi</h1>', (string) $response->getBody());
    }

    public function testAcceptsAStatusEnumNotJustAnInt(): void
    {
        // The int|Status contract: passing a Status case must normalize to its code.
        $this->assertSame(201, $this->responder()->json([], Status::Created)->getStatusCode());
        $this->assertSame(422, $this->responder()->html('x', Status::UnprocessableEntity)->getStatusCode());
        $this->assertSame(404, $this->responder()->text('x', Status::NotFound)->getStatusCode());
    }

    public function testJsonResponse(): void
    {
        $response = $this->responder()->json(['name' => 'will', 'roles' => ['admin']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"name":"will","roles":["admin"]}', (string) $response->getBody());
    }

    public function testJsonDoesNotEscapeSlashesOrUnicode(): void
    {
        $response = $this->responder()->json(['url' => 'https://hydra.dev', 'emoji' => '🐍']);

        $this->assertSame('{"url":"https://hydra.dev","emoji":"🐍"}', (string) $response->getBody());
    }

    public function testJsonThrowsOnUnencodableData(): void
    {
        $this->expectException(\JsonException::class);

        // A resource cannot be JSON-encoded; we want a thrown error, not false.
        $this->responder()->json(['handle' => fopen('php://memory', 'r')]);
    }

    public function testNoContentResponse(): void
    {
        $response = $this->responder()->noContent();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testRedirectResponse(): void
    {
        $response = $this->responder()->redirect('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }
}
