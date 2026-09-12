<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The things a server used to say in HX-* response headers, said in the only
 * channel htmx 4 still listens to: the body it swaps. The 4.x client reads no
 * response header at all (every capitalised HX-* token in the bundle is one it
 * sends), so a directive has to be markup. retarget() emits an out-of-band
 * element htmx applies and then drops itself; the rest are hidden markers the
 * page's script acts on around the swap (see applyTo() in public/js/app.js).
 * Build one through {@see Responder::htmx()}, which has the stream factory.
 */
final class HtmxResponse
{
    /** @var array<string, string> marker attribute => value. */
    private array $markers = [];

    private ?string $target = null;

    private string $swap = 'outerHTML';

    public function __construct(private readonly StreamFactoryInterface $streams) {}

    /**
     * Navigate the browser rather than swapping the response in. Read before
     * the swap, so the element that made the request is left as it was while
     * the page changes underneath it.
     */
    public function redirect(string $url): self
    {
        return $this->mark('redirect', $url);
    }

    /** Push a URL into browser history once the swap has landed. */
    public function pushUrl(string $url): self
    {
        return $this->mark('push-url', $url);
    }

    /** Replace the current URL in browser history once the swap has landed. */
    public function replaceUrl(string $url): self
    {
        return $this->mark('replace-url', $url);
    }

    /**
     * Swap this body into a fixed region instead of the element that asked for
     * it, because an error belongs somewhere the reader can see without losing
     * what they were doing. Target and swap style are one attribute value to
     * htmx, so they are one call here; there is no way to say the second alone.
     */
    public function retarget(string $selector, string $swap = 'outerHTML'): self
    {
        $this->target = $selector;
        $this->swap = $swap;

        return $this;
    }

    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        $body = (string) $response->getBody();

        if ($this->target !== null) {
            $body = '<div hx-swap-oob="' . $this->e($this->swap . ':' . $this->target) . '">'
                . $body
                . '</div>';
        }

        return $response->withBody($this->streams->createStream($body . $this->markup()));
    }

    /**
     * What directive of this name a response carries, or null. The inverse of
     * the builder: without it a caller checking what was asked for (a test,
     * mostly) has to know how a marker is spelled, and three of them knowing is
     * how the last protocol change went unnoticed.
     *
     * Read with a pattern rather than a parser because the attribute is written
     * directly above, in a shape no caller supplies.
     */
    public static function directive(ResponseInterface $response, string $name): ?string
    {
        $pattern = '~\sdata-hydra-' . preg_quote($name, '~') . '="([^"]*)"~';

        if (preg_match($pattern, (string) $response->getBody(), $matches) !== 1) {
            return null;
        }

        return htmlspecialchars_decode($matches[1], ENT_QUOTES);
    }

    private function mark(string $name, string $value): self
    {
        $this->markers['data-hydra-' . $name] = $value;

        return $this;
    }

    private function markup(): string
    {
        if ($this->markers === []) {
            return '';
        }

        $attributes = '';

        foreach ($this->markers as $name => $value) {
            $attributes .= ' ' . $name . '="' . $this->e($value) . '"';
        }

        return '<div' . $attributes . ' hidden></div>';
    }

    /** A list URL carries a query string, so & has to survive the attribute. */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
