<?php

declare(strict_types=1);

namespace Hydra\View\Content;

use DateTimeImmutable;
use RuntimeException;

/**
 * One Markdown file from a ContentDirectory, read as far as a listing needs:
 * its front matter and its body's source, not yet rendered. A file whose
 * front matter couldn't be read keeps its place, with empty meta and the
 * reason in $error, so one bad post never hides the others.
 */
final readonly class ContentFile
{
    /** @param array<string, mixed> $meta the front matter; [] when there is none, or when $error is set */
    public function __construct(
        public string $slug,
        public string $path,
        public DateTimeImmutable $modifiedAt,
        public array $meta,
        public string $body,
        public ?RuntimeException $error = null,
    ) {}
}
