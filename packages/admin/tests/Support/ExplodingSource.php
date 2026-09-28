<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use RuntimeException;

/** A source that must not be read, or whose database has gone away. */
final class ExplodingSource implements SourceInterface
{
    public function page(Criteria $criteria): Page
    {
        throw new RuntimeException('The source was read.');
    }
}
