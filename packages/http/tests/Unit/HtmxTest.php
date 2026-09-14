<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Htmx;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The htmx 4 request headers as this reader sees them, including HX-Target's
 * "tag#id" spelling and an absent header reading as null rather than "".
 */
#[CoversClass(Htmx::class)]
final class HtmxTest extends TestCase
{
    /** @param array<string, string> $headers */
    private function request(array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory)->createServerRequest('GET', '/');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    public function test_is_htmx_true_only_when_header_is_true(): void
    {
        $this->assertTrue(Htmx::fromRequest($this->request(['HX-Request' => 'true']))->isHtmx());
        $this->assertFalse(Htmx::fromRequest($this->request())->isHtmx());
        // htmx only ever sends the literal "true"; anything else is not htmx.
        $this->assertFalse(Htmx::fromRequest($this->request(['HX-Request' => 'false']))->isHtmx());
    }

    public function test_is_boosted(): void
    {
        $this->assertTrue(Htmx::fromRequest($this->request(['HX-Boosted' => 'true']))->isBoosted());
        $this->assertFalse(Htmx::fromRequest($this->request())->isBoosted());
    }

    public function test_reads_the_target_and_the_browsers_current_url(): void
    {
        $htmx = Htmx::fromRequest($this->request([
            'HX-Target' => 'main',
            'HX-Current-URL' => 'https://app.test/users',
        ]));

        $this->assertSame('main', $htmx->target());
        $this->assertSame('https://app.test/users', $htmx->currentUrl());
    }

    public function test_target_id_is_parsed_out_of_the_tag_hash_id_header_htmx_sends(): void
    {
        $htmx = Htmx::fromRequest($this->request(['HX-Target' => 'div#admin-body']));

        $this->assertSame('div#admin-body', $htmx->target());
        $this->assertSame('admin-body', $htmx->targetId());
    }

    public function test_target_id_is_null_when_the_target_element_has_no_id(): void
    {
        $this->assertNull(Htmx::fromRequest($this->request(['HX-Target' => 'main']))->targetId());
        $this->assertNull(Htmx::fromRequest($this->request())->targetId());
    }

    public function test_target_id_is_decoded(): void
    {
        $htmx = Htmx::fromRequest($this->request(['HX-Target' => 'div#user%20list']));

        $this->assertSame('user list', $htmx->targetId());
    }

    public function test_absent_headers_return_null_not_empty_string(): void
    {
        $htmx = Htmx::fromRequest($this->request());

        // null is "not sent", distinct from an empty value a client could send.
        $this->assertNull($htmx->target());
        $this->assertNull($htmx->targetId());
        $this->assertNull($htmx->currentUrl());
    }
}
