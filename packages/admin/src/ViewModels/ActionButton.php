<?php

declare(strict_types=1);

namespace Hydra\Admin\ViewModels;

/** One action as a template draws it: a form posting to $url. */
final readonly class ActionButton
{
    public function __construct(
        public string $url,
        public string $label,
        public ?string $prompt = null,
    ) {}
}
