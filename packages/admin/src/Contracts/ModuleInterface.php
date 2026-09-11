<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use Hydra\Admin\Definition;

/**
 * Module interface
 *
 * define() must stay pure — no request, no database. That is what makes the
 * whole admin inspectable as data and testable without HTTP. Not cacheable: a
 * Field may hold a formatter closure. The routes it compiles to are plain
 * strings and can be.
 */
interface ModuleInterface
{
    public function define(): Definition;
}
