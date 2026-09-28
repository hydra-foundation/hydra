<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\FileHolderInterface;
use Hydra\Admin\Files\Reference;

/**
 * A holder whose answer changes between one read and the next, the way a table
 * does while a row is written: the first read gives its first list, every read
 * after it the second.
 */
final class ChangingHolder implements FileHolderInterface
{
    public int $reads = 0;

    /**
     * @param list<Reference> $first
     * @param list<Reference> $then
     */
    public function __construct(private readonly array $first, private readonly array $then) {}

    public function references(): iterable
    {
        return $this->reads++ === 0 ? $this->first : $this->then;
    }
}
