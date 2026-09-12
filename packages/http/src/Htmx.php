<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader for the HX-* request headers htmx sends.
 *
 * Only for headers htmx actually sends: HX-Trigger, HX-Trigger-Name and
 * HX-Prompt were dropped in htmx 4, and a reader for them would answer null
 * forever rather than say why.
 */
final class Htmx
{
    public function __construct(private readonly ServerRequestInterface $request) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self($request);
    }

    /** True for any request htmx issued (htmx sends the literal "true"). */
    public function isHtmx(): bool
    {
        return $this->request->getHeaderLine('HX-Request') === 'true';
    }

    /** True when the request came from an hx-boost'd link or form. */
    public function isBoosted(): bool
    {
        return $this->request->getHeaderLine('HX-Boosted') === 'true';
    }

    /**
     * The HX-Target header verbatim. htmx sends the target element as
     * "tag#id" (e.g. "div#admin-body"), or bare "tag" when it has no id —
     * {@see targetId()} for the id alone.
     */
    public function target(): ?string
    {
        return $this->header('HX-Target');
    }

    /** The id of the target element, or null when htmx sent no target or an id-less one. */
    public function targetId(): ?string
    {
        $target = $this->header('HX-Target');
        $hash = $target === null ? false : strpos($target, '#');

        return $hash === false ? null : rawurldecode(substr((string) $target, $hash + 1));
    }

    /** The browser's current URL at request time (HX-Current-URL), or null. */
    public function currentUrl(): ?string
    {
        return $this->header('HX-Current-URL');
    }

    private function header(string $name): ?string
    {
        return $this->request->hasHeader($name) ? $this->request->getHeaderLine($name) : null;
    }
}
