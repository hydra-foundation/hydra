<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use Hydra\Admin\SourceDescription;

/**
 * A source that can say which columns it reads and which of those sort, search
 * and filter.
 *
 * Optional on purpose, and separate from {@see SourceInterface} for the same
 * reason the write contracts are separate: a source over a join, a computed
 * view or a remote service has no column list to hand back, and requiring one
 * would make the honest answer "invent something". `admin:check` reports a
 * source that cannot describe itself as unchecked rather than as passing, which
 * is the distinction that matters — the failure this exists to catch is silent,
 * so a check that quietly finds nothing is the same as no check at all.
 *
 * {@see \Hydra\Admin\Sources\TableSource} implements it from the declaration it
 * was built with, so a module extending that gets it for nothing.
 */
interface DescribesColumnsInterface
{
    public function describe(): SourceDescription;
}
