<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Testing\WritableSourceContractTestCase;
use Hydra\Admin\Tests\Support\CrudUserSource;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The contract case against the in-memory source. Nothing of the admin's own is
 * under test — the admin ships no source of its own, that being the whole point
 * of the seam — so this covers nothing and exists to keep the published case
 * honest: a contract with no subclass in its own repository can be broken
 * without anything going red.
 *
 * It rides the whole ladder, since the writable case inherits the row case and
 * the row case inherits the list case, so one fixture answers every rung.
 */
#[CoversNothing]
final class CrudUserSourceContractTest extends WritableSourceContractTestCase
{
    private CrudUserSource $source;

    protected function setUp(): void
    {
        $this->source = new CrudUserSource;
    }

    protected function source(): SourceInterface
    {
        return $this->source;
    }

    protected function rowCount(): int
    {
        return 5;
    }

    protected function sortColumn(): string
    {
        return 'id';
    }

    protected function searchMatchingSomeRows(): string
    {
        // Matches "ada" and nothing else of the five, so narrowing is
        // distinguishable from both ignoring and matching everything.
        return 'ad';
    }

    protected function newRow(): array
    {
        return ['username' => 'linus', 'note' => 'about linus', 'role' => 'user'];
    }

    protected function editedRow(): array
    {
        return ['username' => 'ada-the-second'];
    }
}
