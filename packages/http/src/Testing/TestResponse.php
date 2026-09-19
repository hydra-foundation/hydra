<?php

declare(strict_types=1);

namespace Hydra\Http\Testing;

use Hydra\Http\HtmxResponse;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * A response to assert on. Every failure names the status, the redirect target
 * and the page's text, because "expected 200, got 302" alone sends the reader
 * back to re-run the test with a dump.
 */
final class TestResponse
{
    private const EXCERPT = 300;

    private ?string $body = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function psr(): ResponseInterface
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /** An htmx directive the body carries, such as redirect or push-url, or null. */
    public function directive(string $name): ?string
    {
        return HtmxResponse::directive($this->response, $name);
    }

    public function body(): string
    {
        return $this->body ??= (string) $this->response->getBody();
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status(), "Expected status {$status}.\n" . $this->describe());

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    /** A 3xx with a Location, and that Location when one is given. */
    public function assertRedirect(?string $to = null): self
    {
        $status = $this->status();

        Assert::assertTrue(
            $status >= 300 && $status < 400 && $this->response->hasHeader('Location'),
            'Expected a redirect.' . "\n" . $this->describe(),
        );

        if ($to !== null) {
            Assert::assertSame($to, $this->header('Location'), "Expected a redirect to {$to}.\n" . $this->describe());
        }

        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "Expected the {$name} header.\n" . $this->describe());

        if ($value !== null) {
            Assert::assertSame($value, $this->header($name), "Unexpected {$name} header.\n" . $this->describe());
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        Assert::assertFalse(
            $this->response->hasHeader($name),
            "Expected no {$name} header, got \"{$this->header($name)}\".\n" . $this->describe(),
        );

        return $this;
    }

    /** Matched against the raw body, so markup can be asserted and entities are not decoded. */
    public function assertSee(string $text): self
    {
        Assert::assertTrue(
            str_contains($this->body(), $text),
            "Expected to see \"{$text}\".\n" . $this->describe(),
        );

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertFalse(
            str_contains($this->body(), $text),
            "Expected not to see \"{$text}\".\n" . $this->describe(),
        );

        return $this;
    }

    /** @param list<string> $texts */
    public function assertSeeInOrder(array $texts): self
    {
        $offset = 0;

        foreach ($texts as $text) {
            $found = strpos($this->body(), $text, $offset);

            Assert::assertNotFalse(
                $found,
                "Expected to see \"{$text}\" after what came before it.\n" . $this->describe(),
            );

            $offset = $found + strlen($text);
        }

        return $this;
    }

    public function assertHtmxRedirect(string $to): self
    {
        $directive = $this->directive('redirect');

        Assert::assertSame(
            $to,
            $directive,
            ($directive === null ? 'Expected an htmx redirect directive; there is none.' : "Expected an htmx redirect to {$to}.")
                . "\n" . $this->describe(),
        );

        return $this;
    }

    /** What an htmx swap receives: the fragment alone, without the layout around it. */
    public function assertFragment(): self
    {
        Assert::assertFalse(
            stripos($this->body(), '<!doctype') !== false,
            "Expected a fragment, got a full document.\n" . $this->describe(),
        );

        return $this;
    }

    private function describe(): string
    {
        $lines = ['Response: ' . $this->status() . ' ' . $this->response->getReasonPhrase()];

        if ($this->response->hasHeader('Location')) {
            $lines[] = 'Location: ' . $this->header('Location');
        }

        $text = $this->text();
        $lines[] = $text === '' ? 'Body: (empty)' : 'Body: ' . $text;

        return implode("\n", $lines);
    }

    /** The body as a reader sees it: no scripts, styles or tags, whitespace collapsed. */
    private function text(): string
    {
        $text = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $this->body());
        $text = trim((string) preg_replace('~\s+~', ' ', strip_tags($text)));

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT) . '…' : $text;
    }
}
