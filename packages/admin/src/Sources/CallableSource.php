<?php

declare(strict_types=1);

namespace Hydra\Admin\Sources;

use Closure;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * Adapts a plain callable into a source, for modules that would rather write
 * four lines than implement the interface.
 */
final class CallableSource implements SourceInterface
{
    private readonly Closure $pager;

    /** @param callable(Criteria): Page $pager */
    public function __construct(callable $pager)
    {
        $this->pager = $pager(...);
    }

    public function page(Criteria $criteria): Page
    {
        return ($this->pager)($criteria);
    }
}
