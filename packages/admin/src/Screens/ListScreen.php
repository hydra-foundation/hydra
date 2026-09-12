<?php

declare(strict_types=1);

namespace Hydra\Admin\Screens;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ScreenInterface;

/**
 * The module root: a paginated, sortable, filterable table of the source's rows.
 */
final class ListScreen implements ScreenInterface
{
    /** @param class-string|null $ability */
    public function __construct(private readonly ?string $ability = null) {}

    public function name(): string
    {
        return 'list';
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '';
    }

    public function handler(): array
    {
        return [AdminController::class, 'list'];
    }

    public function ability(): ?string
    {
        return $this->ability;
    }
}
