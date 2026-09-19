<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Responder;
use Hydra\Http\Testing\TestResponse;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestResponse::class)]
final class TestResponseTest extends TestCase
{
    public function test_it_exposes_the_response_it_wraps(): void
    {
        $psr = new Response(201, ['X-Id' => '7'], 'made');
        $response = new TestResponse($psr);

        $this->assertSame($psr, $response->psr());
        $this->assertSame(201, $response->status());
        $this->assertSame('7', $response->header('X-Id'));
        $this->assertSame('made', $response->body());
    }

    public function test_the_body_is_read_once(): void
    {
        // A stream that cannot seek reads empty the second time, and a chain of
        // assertSee calls would fail on the second link for no visible reason.
        $stream = (new Psr17Factory)->createStreamFromResource($this->unseekable('once'));
        $response = new TestResponse((new Response(200))->withBody($stream));

        $response->assertSee('once')->assertSee('once');
    }

    public function test_assertions_chain(): void
    {
        $response = $this->html('<p>hello</p>');

        $this->assertSame($response, $response->assertOk()->assertSee('hello')->assertFragment());
    }

    public function test_a_status_mismatch_names_the_status_and_the_redirect(): void
    {
        $this->assertFails(
            fn () => (new TestResponse(new Response(302, ['Location' => '/login'])))->assertOk(),
            ['Expected status 200.', 'Response: 302 Found', 'Location: /login'],
        );
    }

    public function test_a_failure_shows_the_page_text_not_its_markup(): void
    {
        $this->assertFails(
            fn () => $this->html(
                '<!doctype html><style>p{}</style><script>boot()</script><h1>Server   Error</h1>',
                500,
            )->assertOk(),
            ['Body: Server Error'],
        );
    }

    public function test_a_long_body_is_cut_short_in_the_failure(): void
    {
        try {
            $this->html(str_repeat('a', 1000))->assertSee('b');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString(str_repeat('a', 300) . '…', $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('a', 301), $e->getMessage());

            return;
        }

        $this->fail('assertSee passed on a body without the text.');
    }

    public function test_an_empty_body_says_so(): void
    {
        $this->assertFails(fn () => (new TestResponse(new Response(204)))->assertOk(), ['Body: (empty)']);
    }

    public function test_a_redirect_passes_with_or_without_a_target(): void
    {
        (new TestResponse(new Response(303, ['Location' => '/done'])))
            ->assertRedirect()
            ->assertRedirect('/done');
    }

    public function test_a_redirect_to_elsewhere_fails(): void
    {
        $this->assertFails(
            fn () => (new TestResponse(new Response(302, ['Location' => '/login'])))->assertRedirect('/admin'),
            ['Expected a redirect to /admin.'],
        );
    }

    public function test_a_3xx_without_a_location_is_not_a_redirect(): void
    {
        $this->assertFails(fn () => (new TestResponse(new Response(304)))->assertRedirect(), ['Expected a redirect.']);
    }

    public function test_a_location_on_a_2xx_is_not_a_redirect(): void
    {
        $this->assertFails(
            fn () => (new TestResponse(new Response(201, ['Location' => '/posts/1'])))->assertRedirect(),
            ['Expected a redirect.'],
        );
    }

    public function test_header_presence_and_value(): void
    {
        $response = new TestResponse(new Response(200, ['Cache-Control' => 'no-store']));

        $response->assertHeader('cache-control')->assertHeader('Cache-Control', 'no-store')->assertHeaderMissing('ETag');

        $this->assertFails(fn () => $response->assertHeader('ETag'), ['Expected the ETag header.']);
        $this->assertFails(fn () => $response->assertHeader('Cache-Control', 'private'), ['Unexpected Cache-Control header.']);
        $this->assertFails(
            fn () => $response->assertHeaderMissing('Cache-Control'),
            ['Expected no Cache-Control header, got "no-store".'],
        );
    }

    public function test_see_matches_the_raw_markup(): void
    {
        $response = $this->html('<input name="username"> O&#039;Brien');

        $response->assertSee('name="username"')->assertSee('O&#039;Brien')->assertDontSee("O'Brien");

        $this->assertFails(fn () => $response->assertDontSee('username'), ['Expected not to see "username".']);
    }

    public function test_see_in_order(): void
    {
        $response = $this->html('<li>one</li><li>two</li><li>three</li>');

        $response->assertSeeInOrder(['one', 'two', 'three']);

        $this->assertFails(fn () => $response->assertSeeInOrder(['two', 'one']), ['Expected to see "one" after']);
    }

    public function test_see_in_order_does_not_count_one_occurrence_twice(): void
    {
        $this->assertFails(fn () => $this->html('ab')->assertSeeInOrder(['ab', 'b']), ['Expected to see "b"']);
    }

    public function test_the_htmx_redirect_directive(): void
    {
        $marked = new TestResponse(
            (new Responder(new Psr17Factory, new Psr17Factory))->htmx()->redirect('/login')->applyTo(new Response(200)),
        );

        $marked->assertHtmxRedirect('/login');
        $this->assertSame('/login', $marked->directive('redirect'));
        $this->assertNull($marked->directive('push-url'));

        $this->assertFails(fn () => $marked->assertHtmxRedirect('/admin'), ['Expected an htmx redirect to /admin.']);
        $this->assertFails(
            fn () => $this->html('<p>no marker</p>')->assertHtmxRedirect('/login'),
            ['Expected an htmx redirect directive; there is none.'],
        );
    }

    public function test_a_full_document_is_not_a_fragment(): void
    {
        $this->assertFails(
            fn () => $this->html('<!DOCTYPE html><html><body><form></form></body></html>')->assertFragment(),
            ['Expected a fragment, got a full document.'],
        );
    }

    private function html(string $body, int $status = 200): TestResponse
    {
        return new TestResponse(new Response($status, ['Content-Type' => 'text/html'], $body));
    }

    /**
     * @param callable(): mixed $assertion
     * @param list<string> $messages
     */
    private function assertFails(callable $assertion, array $messages): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            foreach ($messages as $message) {
                $this->assertStringContainsString($message, $e->getMessage());
            }

            return;
        }

        $this->fail('The assertion passed.');
    }

    /** @return resource */
    private function unseekable(string $contents)
    {
        $pipe = popen('printf %s ' . escapeshellarg($contents), 'r');
        $this->assertNotFalse($pipe);

        return $pipe;
    }
}
