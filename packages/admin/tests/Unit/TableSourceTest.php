<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Criteria;
use Hydra\Admin\SourceDescription;
use Hydra\Admin\Sources\TableSource;
use Hydra\Database\PdoConnection;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declared read side of a table. Two things are worth testing here and the
 * contract test cases cover neither: that a declaration which cannot be honoured
 * is refused where it is written, and that each of the four lists actually
 * reaches the SQL. A filter that silently matches nothing and a filter that
 * silently matches everything look identical from the module.
 */
#[CoversClass(TableSource::class)]
#[CoversClass(SourceDescription::class)]
final class TableSourceTest extends TestCase
{
    private const COLUMNS = ['id', 'table_name', 'message', 'secret', 'created_at'];

    public function test_it_pages_every_row_when_nothing_is_asked_of_it(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10));

        $this->assertSame(5, $page->total);
        $this->assertCount(5, $page->rows);
    }

    public function test_it_reads_only_the_columns_it_was_declared_with(): void
    {
        // The table has a sixth column. A source that selected * would leak it
        // into every screen that renders a row.
        $row = $this->source()->find('1');

        $this->assertSame(self::COLUMNS, array_keys($row ?? []));
    }

    public function test_a_filter_narrows_the_page(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, filters: ['table_name' => 'settings']));

        $this->assertSame(2, $page->total);
        $this->assertSame(['settings', 'settings'], array_column($page->rows, 'table_name'));
    }

    public function test_a_filter_naming_an_undeclared_column_is_ignored_rather_than_applied(): void
    {
        // Criteria only carries keys the blueprint declared filterable, so this
        // is the belt to that braces: an unknown key must not become SQL.
        $page = $this->source()->page(new Criteria(perPage: 10, filters: ['message' => 'renamed the account']));

        $this->assertSame(5, $page->total, 'message is not filterable, so the filter must not apply.');
    }

    public function test_search_reaches_every_searchable_column(): void
    {
        $this->assertSame(2, $this->source()->page(new Criteria(perPage: 10, search: 'users'))->total);
        $this->assertSame(1, $this->source()->page(new Criteria(perPage: 10, search: 'theme'))->total);
    }

    public function test_search_does_not_reach_a_column_left_out_of_searchable(): void
    {
        // "classified" is in the table, in a column the declaration omitted.
        $this->assertSame(0, $this->source()->page(new Criteria(perPage: 10, search: 'classified'))->total);
    }

    public function test_a_wildcard_search_term_is_escaped_rather_than_matching_everything(): void
    {
        $this->assertSame(0, $this->source()->page(new Criteria(perPage: 10, search: '%'))->total);
        $this->assertSame(0, $this->source()->page(new Criteria(perPage: 10, search: '_'))->total);
    }

    public function test_search_and_filter_narrow_together(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, search: 'renamed', filters: ['table_name' => 'users']));

        $this->assertSame(2, $page->total);
    }

    public function test_it_sorts_by_a_sortable_column(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'table_name', direction: 'asc'));

        $this->assertSame('activity', $page->rows[0]['table_name']);
    }

    public function test_it_falls_back_to_the_default_sort_when_asked_for_a_column_it_does_not_offer(): void
    {
        // Criteria::fromQuery() already whitelists against the blueprint. This
        // is what keeps the class safe to call from anywhere else.
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'secret', direction: 'asc'));

        $this->assertSame([1, 2, 3, 4, 5], array_column($page->rows, 'id'));
    }

    public function test_paging_is_a_partition(): void
    {
        $first = $this->source()->page(new Criteria(page: 1, perPage: 2));
        $second = $this->source()->page(new Criteria(page: 2, perPage: 2));

        $this->assertSame([1, 2], array_column($first->rows, 'id'));
        $this->assertSame([3, 4], array_column($second->rows, 'id'));
        $this->assertSame(5, $first->total, 'the total counts matching rows, not the page.');
    }

    public function test_find_returns_null_for_an_id_no_row_has(): void
    {
        $this->assertNull($this->source()->find('999'));
    }

    public function test_find_refuses_an_id_php_would_have_read_as_a_neighbouring_row(): void
    {
        $this->assertNull($this->source()->find('1-not-an-id'));
        $this->assertNull($this->source()->find('1 OR 1=1'));
    }

    public function test_it_refuses_a_sortable_column_it_does_not_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('sortable names "nope", which is not a column it reads.');

        $this->build(sortable: ['id', 'nope']);
    }

    public function test_it_refuses_a_filterable_column_it_does_not_read(): void
    {
        // The mistake this exists for: a filter key copied from another module.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('filterable names "role", which is not a column it reads.');

        $this->build(filterable: ['role']);
    }

    public function test_it_refuses_a_searchable_column_it_does_not_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('searchable names "username", which is not a column it reads.');

        $this->build(searchable: ['username']);
    }

    public function test_it_refuses_a_default_sort_it_does_not_read(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('defaultSort names "ordering", which is not a column it reads.');

        $this->build(defaultSort: 'ordering');
    }

    public function test_it_refuses_a_table_name_that_is_not_an_identifier(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"audit; DROP TABLE users" is not a table name.');

        $this->build(table: 'audit; DROP TABLE users');
    }

    public function test_it_refuses_a_column_name_that_is_not_an_identifier(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"COUNT(*)" is not a column name.');

        $this->build(columns: ['id', 'COUNT(*)']);
    }

    public function test_it_refuses_a_declaration_with_no_columns(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a source reads at least one column.');

        $this->build(columns: []);
    }

    public function test_the_exception_names_the_subclass_that_declared_it(): void
    {
        $this->expectExceptionMessage('names "nope"');

        try {
            $this->build(sortable: ['nope']);
        } catch (LogicException $e) {
            $this->assertStringStartsWith(TableSource::class . ':', $e->getMessage());

            throw $e;
        }
    }

    public function test_it_hands_back_the_declaration_it_was_built_with(): void
    {
        // The whole point of the description: what `admin:check` compares a
        // module against is the source's own four lists, not a schema read.
        $description = $this->source()->describe();

        $this->assertSame('audit', $description->table);
        $this->assertSame(self::COLUMNS, $description->columns);
        $this->assertSame(['id', 'table_name', 'created_at'], $description->sortable);
        $this->assertSame(['table_name', 'message'], $description->searchable);
        $this->assertSame(['table_name'], $description->filterable);
        $this->assertSame('id', $description->defaultSort);
    }

    public function test_the_description_answers_for_each_list_separately(): void
    {
        // A column can be read and not sorted by, or sorted by and not
        // searched; collapsing the four into one answer would hide exactly the
        // mismatch this exists to find.
        $description = $this->source()->describe();

        $this->assertTrue($description->reads('secret'));
        $this->assertFalse($description->sortsBy('secret'));
        $this->assertFalse($description->searches('secret'));
        $this->assertFalse($description->filtersBy('secret'));

        $this->assertTrue($description->sortsBy('created_at'));
        $this->assertFalse($description->searches('created_at'));
    }

    public function test_a_column_nothing_declared_is_absent_from_every_list(): void
    {
        $description = $this->source()->describe();

        $this->assertFalse($description->reads('ignored'));
        $this->assertFalse($description->sortsBy('ignored'));
        $this->assertFalse($description->searches('ignored'));
        $this->assertFalse($description->filtersBy('ignored'));
    }

    private function source(): TableSource
    {
        return $this->build();
    }

    /**
     * @param list<string>|null $columns
     * @param list<string>|null $sortable
     * @param list<string>|null $searchable
     * @param list<string>|null $filterable
     */
    private function build(
        string $table = 'audit',
        ?array $columns = null,
        ?array $sortable = null,
        ?array $searchable = null,
        ?array $filterable = null,
        string $defaultSort = 'id',
    ): TableSource {
        return new TableSource(
            new PdoConnection($this->database()),
            $table,
            $columns ?? self::COLUMNS,
            $sortable ?? ['id', 'table_name', 'created_at'],
            $searchable ?? ['table_name', 'message'],
            $filterable ?? ['table_name'],
            $defaultSort,
        );
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec(
            'CREATE TABLE audit (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name TEXT NOT NULL,
                message TEXT NOT NULL,
                secret TEXT NOT NULL,
                created_at TEXT NOT NULL,
                undeclared TEXT NOT NULL DEFAULT \'\'
            )'
        );

        $rows = [
            ['users', 'renamed the account', 'classified', '2026-09-01 10:00:00'],
            ['users', 'renamed the account', 'classified', '2026-09-02 10:00:00'],
            ['settings', 'changed the theme', 'classified', '2026-09-03 10:00:00'],
            ['settings', 'changed the locale', 'classified', '2026-09-04 10:00:00'],
            ['activity', 'pruned an old entry', 'classified', '2026-09-05 10:00:00'],
        ];

        foreach ($rows as $row) {
            $pdo->prepare('INSERT INTO audit (table_name, message, secret, created_at) VALUES (?, ?, ?, ?)')
                ->execute($row);
        }

        return $pdo;
    }
}
