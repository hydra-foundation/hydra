<?php

declare(strict_types=1);

namespace Hydra\View;

/** A parsed content file: what its front matter said, and its body as HTML. */
final readonly class Document
{
    /** @param array<string, mixed> $meta the front matter; [] when the file has none */
    public function __construct(
        public array $meta,
        public HtmlView $html,
    ) {}
}
