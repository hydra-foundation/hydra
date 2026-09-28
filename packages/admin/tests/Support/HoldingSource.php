<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Files\Reference;
use Hydra\Admin\Page;
use LogicException;

/**
 * A source that says which files it holds, rows its list hides included, and
 * so must never be walked for them. A holder of the app's own is one of these
 * without the page().
 */
final class HoldingSource implements SourceInterface, FileHolderInterface
{
    /** @param list<Reference> $references */
    public function __construct(private readonly array $references) {}

    public function page(Criteria $criteria): Page
    {
        throw new LogicException('A source that holds its own files was walked.');
    }

    public function references(): iterable
    {
        return $this->references;
    }
}
