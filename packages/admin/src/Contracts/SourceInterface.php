<?php

declare(strict_types=1);

namespace Hydra\Admin\Contracts;

use Hydra\Admin\Criteria;
use Hydra\Admin\Page;

/**
 * How a module reads its rows. Hydra has no ORM, so nothing is inferred: a
 * module hands the admin one of these, and the admin never touches a database
 * of its own accord.
 */
interface SourceInterface
{
    public function page(Criteria $criteria): Page;
}
