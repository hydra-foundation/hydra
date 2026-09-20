<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * The tally behind a module's filter links, fetched after the list has already
 * rendered.
 *
 * It is a screen of its own rather than part of the list because a count per
 * link is a query per link, and the table is what the visitor is waiting for.
 * {@see \Hydra\Admin\Definition::compile()} adds one wherever links are
 * declared, so no module asks for it by hand.
 */
final class LinkCountsScreen implements ScreenInterface
{
    /** @param class-string|null $ability */
    public function __construct(private readonly ?string $ability = null) {}

    public function name(): string
    {
        return 'counts';
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return 'counts';
    }

    public function handler(): array
    {
        return [AdminController::class, 'counts'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}
