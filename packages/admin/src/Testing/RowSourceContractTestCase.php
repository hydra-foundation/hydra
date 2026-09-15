<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use Hydra\Admin\Contracts\RowSourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Everything {@see SourceContractTestCase} asks, plus what a source owes the
 * screens that open one row.
 *
 * The id reaching {@see RowSourceInterface::find()} comes out of the URL, so it
 * is a string somebody chose, and the two things that go wrong with it are worth
 * naming. A source that raises on an id it does not recognise turns a stale
 * bookmark into a 500. A source that coerces one until it matches something —
 * the `(int) $id` a numeric key invites — answers a URL naming no row with a row
 * anyway, and the reader has no way to tell they are not looking at what they
 * asked for.
 */
abstract class RowSourceContractTestCase extends SourceContractTestCase
{
    /**
     * The source under test, as a reader of single rows.
     *
     * Not a second hook: {@see source()} is the one the fixture implements, and
     * this states the interface the cases below need it to also satisfy.
     */
    final protected function rowSource(): RowSourceInterface
    {
        $source = $this->source();

        $this->assertInstanceOf(
            RowSourceInterface::class,
            $source,
            'This case is for a source that reads one row; a source without one belongs to SourceContractTestCase.',
        );

        return $source;
    }

    /** An id no row has. Override where the default could collide with a real one. */
    protected function unknownId(): string
    {
        return '9007199254740991';
    }

    public function test_every_listed_row_can_be_opened_from_the_list(): void
    {
        // The round trip the admin performs on every screen: a row is listed,
        // its id becomes the href of a link, and the next request asks find()
        // for it. Two halves that disagree are a table whose every row 404s.
        foreach ($this->walk() as $row) {
            $id = $this->idOf($row);
            $found = $this->rowSource()->find($id);

            $this->assertNotNull($found, "The list offered row {$id} and find() does not know it.");
            $this->assertSame($id, $this->idOf($found));
        }
    }

    public function test_an_unknown_id_is_a_miss_rather_than_an_error(): void
    {
        // null, not an exception: a deleted row's URL stays in somebody's
        // history, and the screen's job is to say it is gone.
        $this->assertNull($this->rowSource()->find($this->unknownId()));
    }

    /** @return array<string, array{string}> */
    public static function idsThatAreNotTheRowsOwn(): array
    {
        return [
            'a suffix' => ['%s-not-an-id'],
            'trailing whitespace' => ["%s\t"],
            'a leading plus' => ['+%s'],
            'a tautology appended' => ["%s' OR '1'='1"],
            'a wildcard appended' => ['%s%%'],
        ];
    }

    #[DataProvider('idsThatAreNotTheRowsOwn')]
    public function test_a_lookup_never_answers_with_a_row_it_was_not_asked_for(string $template): void
    {
        $real = $this->idOf($this->walk()[0]);
        $asked = sprintf($template, $real);

        $found = $this->rowSource()->find($asked);
        $answered = $found === null ? null : $this->idOf($found);

        // A miss and the row itself are both fine; answering with a *different*
        // row is not. A cast that reads "1-not-an-id" as 1 is how the URL of a
        // row nobody has renders as the row next to it.
        $this->assertContains(
            $answered,
            [null, $asked],
            sprintf('find(%s) answered with the row at %s.', $asked, var_export($answered, true)),
        );
    }
}
