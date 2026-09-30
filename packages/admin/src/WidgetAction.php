<?php

declare(strict_types=1);

namespace Hydra\Admin;

use Hydra\Admin\Contracts\ModuleActionInterface;

/**
 * One button on a dashboard card: what it says, what it asks first, and the
 * {@see ModuleActionInterface} it runs. A card's button is a module action
 * that lives where its subject is shown, rather than above a table.
 */
final readonly class WidgetAction
{
    /** @param class-string<ModuleActionInterface> $runs a service id */
    public function __construct(
        public string $name,
        public string $label,
        public string $runs,
        public ?string $confirm = null,
    ) {}
}
