<?php

declare(strict_types=1);

namespace Hydra\View;

use Stringable;

/**
 * Marks a string as already-safe HTML that must NOT be escaped again
 */
final class HtmlView implements Stringable
{
    public function __construct(private readonly string|Stringable $html) {}

    public function __toString(): string
    {
        return (string) $this->html;
    }
}
