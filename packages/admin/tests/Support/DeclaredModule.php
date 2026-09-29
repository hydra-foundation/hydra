<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;

/**
 * A module whose declaration is handed to it, for tests about how modules
 * relate to each other rather than what any one of them shows. A class per
 * arrangement would be a dozen files that differ by a slug.
 */
final class DeclaredModule implements ModuleInterface
{
    public function __construct(private readonly Definition $definition) {}

    public function define(): Definition
    {
        return $this->definition;
    }
}
