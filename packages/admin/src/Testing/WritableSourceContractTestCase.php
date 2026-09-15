<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;

/**
 * Everything {@see RowSourceContractTestCase} asks, plus what a source owes the
 * form and delete screens.
 *
 * For a source implementing all three write contracts, which is what a module
 * with the full set of screens needs. A source that can be corrected but not
 * added to — or added to but never emptied — extends
 * {@see RowSourceContractTestCase} instead; splitting this into one case per
 * write contract is worth doing when such a source exists and not before.
 *
 * The contract that matters most here is the one
 * {@see CreateSourceInterface::create()} states in words and nothing enforced:
 * the id it returns is the id {@see \Hydra\Admin\Contracts\RowSourceInterface::find()}
 * answers to. The admin redirects to the new row the moment a create succeeds,
 * so a source returning an id of its own devising sends every successful
 * creation to a screen that cannot find what was just written.
 */
abstract class WritableSourceContractTestCase extends RowSourceContractTestCase
{
    /**
     * A row this source would accept, keyed the way an {@see \Hydra\Admin\Input}
     * would deliver it. It must not collide with a row already there, or a
     * source that rejects duplicates fails these for the right reason at the
     * wrong time.
     *
     * @return array<string, mixed>
     */
    abstract protected function newRow(): array;

    /**
     * A change this source would accept on an existing row, different from what
     * {@see newRow()} writes so the read-back can tell them apart.
     *
     * @return array<string, mixed>
     */
    abstract protected function editedRow(): array;

    /**
     * The part of a written row that can be read back, for sources that
     * transform what they store. A password is the standing example: it is
     * written, it is never returned, and a case that insisted otherwise would
     * be asking for the defect.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function readBack(array $data): array
    {
        return $data;
    }

    final protected function createSource(): CreateSourceInterface
    {
        $source = $this->source();
        $this->assertInstanceOf(CreateSourceInterface::class, $source);

        return $source;
    }

    final protected function updateSource(): UpdateSourceInterface
    {
        $source = $this->source();
        $this->assertInstanceOf(UpdateSourceInterface::class, $source);

        return $source;
    }

    final protected function deleteSource(): DeleteSourceInterface
    {
        $source = $this->source();
        $this->assertInstanceOf(DeleteSourceInterface::class, $source);

        return $source;
    }

    public function test_the_id_a_create_returns_is_the_id_the_row_answers_to(): void
    {
        $id = $this->createSource()->create($this->newRow());
        $found = $this->rowSource()->find($id);

        $this->assertNotNull(
            $found,
            "create() returned {$id} and find() does not know it, so the screen a create redirects to is a dead end.",
        );
        $this->assertSame($id, $this->idOf($found));
    }

    public function test_a_created_row_holds_what_it_was_given(): void
    {
        $data = $this->newRow();
        $found = $this->rowSource()->find($this->createSource()->create($data));

        $this->assertNotNull($found);

        foreach ($this->readBack($data) as $column => $value) {
            $this->assertArrayHasKey($column, $found, "The created row has no {$column} at all.");
            $this->assertSame((string) $value, (string) $found[$column]);
        }
    }

    public function test_a_create_adds_exactly_one_row_to_the_list(): void
    {
        // Through the list rather than through find(): a write the row reader
        // can see and the list cannot is a row nobody will ever navigate to.
        $this->createSource()->create($this->newRow());

        $this->assertCount($this->rowCount() + 1, $this->walk());
    }

    public function test_an_update_is_visible_to_the_next_read(): void
    {
        $id = $this->idOf($this->walk()[0]);
        $this->updateSource()->update($id, $this->editedRow());

        $found = $this->rowSource()->find($id);

        $this->assertNotNull($found);

        foreach ($this->readBack($this->editedRow()) as $column => $value) {
            $this->assertSame((string) $value, (string) $found[$column]);
        }
    }

    public function test_an_update_rewrites_a_row_rather_than_adding_one(): void
    {
        $this->updateSource()->update($this->idOf($this->walk()[0]), $this->editedRow());

        $this->assertCount($this->rowCount(), $this->walk());
    }

    public function test_a_deleted_row_is_gone_from_both_the_row_reader_and_the_list(): void
    {
        // The row deleted is one this case created, not one the fixture came
        // with: a source is entitled to refuse a particular row — the account
        // you are signed in as, the first user — and that refusal is policy
        // rather than a contract breach.
        $id = $this->createSource()->create($this->newRow());

        $this->deleteSource()->delete($id);

        $this->assertNull($this->rowSource()->find($id), 'The row is still readable after being deleted.');
        $this->assertNotContains($id, $this->idsOf($this->walk()), 'The deleted row is still listed.');
        $this->assertCount($this->rowCount(), $this->walk());
    }
}
