<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Console\AdminCheckCommand;
use Hydra\Admin\Criteria;
use Hydra\Admin\DateRange;
use Hydra\Admin\FieldType;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\SourceDescription;
use Hydra\Admin\Sources\ContentSource;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\LineFrontMatter;
use Hydra\Admin\Tests\Support\PostSource;
use Hydra\Admin\Tests\Support\PostsModule;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Core\Testing\FakeContainer;
use Hydra\View\Content\ContentDirectory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A directory of Markdown files as a read-only admin source: five posts and
 * one whose front matter is broken, searched, sorted, filtered and paged in
 * memory the way a table module is in SQL.
 */
#[CoversClass(ContentSource::class)]
final class ContentSourceTest extends TestCase
{
    private const NOW = '2026-10-15';

    private const COLUMNS = ['slug', 'title', 'date', 'tags', 'status'];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-posts-' . bin2hex(random_bytes(6));
        mkdir($this->dir);

        $this->post('hello-world', ['title: Hello world', 'date: 2026-10-01', 'tags: [hydra, php]'], 'The first post, about the framework.');
        $this->post('second', ['title: Second thoughts', 'date: 2026-10-05', 'tags: [php]'], 'More words.');
        $this->post('same-day', ['title: Another', 'date: 2026-10-05', 'tags: []'], 'Shares a day with second.');
        $this->post('draft', ['title: Not yet', 'date: 2026-10-08', 'tags: [hydra]', 'draft: true'], 'Unfinished.');
        $this->post('future', ['title: Zebra crossing', 'date: 2026-12-01', 'tags: [life]'], 'Scheduled.');
        file_put_contents($this->dir . '/broken.md', "---\ntitle: Broken\nthis line is not yaml\n---\nStill searchable body.");
        file_put_contents($this->dir . '/README.txt', 'not content');
        touch($this->dir . '/hello-world.md', 1_790_000_000);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_every_file_is_a_row_with_the_broken_one_flagged(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'slug'));

        $this->assertSame(6, $page->total);
        $this->assertSame(['broken', 'draft', 'future', 'hello-world', 'same-day', 'second'], array_column($page->rows, 'slug'));

        $broken = $page->rows[0];
        $this->assertSame('broken', $broken['id']);
        $this->assertStringContainsString('not valid YAML', (string) $broken['problem']);
        $this->assertArrayNotHasKey('title', $broken);
        $this->assertNull($page->rows[1]['problem']);
    }

    public function test_a_row_is_flat_enough_to_print(): void
    {
        $row = $this->source()->find('hello-world');

        $this->assertNotNull($row);
        $this->assertSame('hello-world', $row['id']);
        $this->assertSame('hello-world', $row['slug']);
        $this->assertSame('Hello world', $row['title']);
        $this->assertSame('2026-10-01T00:00:00+00:00', $row['date'], 'a date as ISO 8601');
        $this->assertSame('hydra, php', $row['tags'], 'a list joined for display');
        $this->assertSame('The first post, about the framework.', $row['body']);
        $this->assertSame((new DateTimeImmutable('@1790000000'))->format(DATE_ATOM), $row['modified_at']);
        $this->assertNull($row['problem']);
        $this->assertSame('published', $row['status'], 'map: columns are in a found row too');
    }

    public function test_find_takes_only_a_slug(): void
    {
        $this->assertNull($this->source()->find('missing'));
        $this->assertNull($this->source()->find('../hello-world'));
        $this->assertNull($this->source()->find('README'));
        $this->assertStringContainsString('not valid YAML', (string) ($this->source()->find('broken')['problem'] ?? ''));
    }

    public function test_a_list_page_leaves_the_body_out_unless_it_is_a_column(): void
    {
        $without = $this->source()->page(new Criteria(perPage: 10));
        $with = $this->source(columns: [...self::COLUMNS, 'body'])->page(new Criteria(perPage: 10));

        $this->assertArrayNotHasKey('body', $without->rows[0]);
        $this->assertArrayHasKey('body', $with->rows[0]);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function searches(): iterable
    {
        yield 'a title, any case' => ['HELLO', ['hello-world']];
        yield 'a tag' => ['hydra', ['draft', 'hello-world']];
        yield 'the body' => ['framework', ['hello-world']];
        yield 'a broken file by its body' => ['still searchable', ['broken']];
        yield 'nothing' => ['no such words', []];
    }

    /** @param list<string> $slugs */
    #[DataProvider('searches')]
    public function test_search_looks_in_the_searchable_columns(string $term, array $slugs): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'slug', search: $term));

        $this->assertSame($slugs, array_column($page->rows, 'slug'));
    }

    public function test_search_ignores_columns_it_was_not_given(): void
    {
        // "first post" is only in hello-world's body.
        $page = $this->source(searchable: ['title'])->page(new Criteria(perPage: 10, search: 'first post'));

        $this->assertSame(0, $page->total);
    }

    public function test_dates_sort_as_dates_and_ties_fall_to_the_slug(): void
    {
        $asc = $this->source()->page(new Criteria(perPage: 10, sort: 'date'));
        $desc = $this->source()->page(new Criteria(perPage: 10, sort: 'date', direction: 'desc'));

        // A file with no date sorts first going up.
        $this->assertSame(['broken', 'hello-world', 'same-day', 'second', 'draft', 'future'], array_column($asc->rows, 'slug'));
        $this->assertSame(['future', 'draft', 'second', 'same-day', 'hello-world', 'broken'], array_column($desc->rows, 'slug'));
    }

    public function test_text_sorts_naturally_without_case(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'title'));

        $this->assertSame(['Another', 'Hello world', 'Not yet', 'Second thoughts', 'Zebra crossing'], array_values(array_filter(array_column($page->rows, 'title'))));
    }

    public function test_numbers_sort_as_numbers(): void
    {
        $this->post('nine', ['title: Nine', 'order: 9'], 'x');
        $this->post('ten', ['title: Ten', 'order: 10'], 'x');

        $page = $this->source(columns: [...self::COLUMNS, 'order'], sortable: ['order'])->page(new Criteria(perPage: 10, sort: 'order', direction: 'desc'));

        $this->assertSame(['ten', 'nine'], array_slice(array_column($page->rows, 'slug'), 0, 2));
    }

    public function test_an_undeclared_sort_falls_back_to_the_default(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'body', direction: 'desc'));

        $this->assertSame('future', $page->rows[0]['slug'], 'by date, the default, descending');
    }

    public function test_a_list_column_filters_by_what_it_contains(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'slug', filters: ['tags' => 'php']));

        $this->assertSame(['hello-world', 'second'], array_column($page->rows, 'slug'));
    }

    public function test_a_computed_column_filters_and_sorts(): void
    {
        $scheduled = $this->source()->page(new Criteria(perPage: 10, filters: ['status' => 'scheduled']));
        $drafts = $this->source()->page(new Criteria(perPage: 10, filters: ['status' => 'draft']));
        $byStatus = $this->source(sortable: ['status'])->page(new Criteria(perPage: 10, sort: 'status'));

        $this->assertSame(['future'], array_column($scheduled->rows, 'slug'));
        $this->assertSame(['draft'], array_column($drafts->rows, 'slug'));
        $this->assertSame(['broken', 'draft', 'hello-world', 'same-day', 'second', 'future'], array_column($byStatus->rows, 'slug'));
    }

    public function test_a_boolean_filters_as_the_admin_spells_it(): void
    {
        $page = $this->source(columns: [...self::COLUMNS, 'draft'], filterable: ['draft'])->page(new Criteria(perPage: 10, filters: ['draft' => '1']));

        $this->assertSame(['draft'], array_column($page->rows, 'slug'));
    }

    public function test_a_filter_it_was_not_declared_with_is_ignored(): void
    {
        $page = $this->source()->page(new Criteria(perPage: 10, filters: ['title' => 'Second thoughts']));

        $this->assertSame(6, $page->total);
    }

    public function test_a_date_range_narrows_by_day(): void
    {
        $range = DateRange::fromDays('2026-10-05', '2026-10-08', FieldType::Date, new DateTimeZone('UTC'));
        $page = $this->source()->page(new Criteria(perPage: 10, sort: 'slug', ranges: ['date' => $range]));

        $this->assertSame(['draft', 'same-day', 'second'], array_column($page->rows, 'slug'));
    }

    public function test_the_modified_time_narrows_by_day_too(): void
    {
        $range = DateRange::fromDays(null, '2026-09-30', FieldType::DateTime, new DateTimeZone('UTC'));
        $page = $this->source(filterable: ['modified_at'])->page(new Criteria(perPage: 10, ranges: ['modified_at' => $range]));

        $this->assertSame(['hello-world'], array_column($page->rows, 'slug'));
    }

    public function test_it_pages(): void
    {
        $source = $this->source();
        $first = $source->page(new Criteria(page: 1, perPage: 4, sort: 'slug'));
        $second = $source->page(new Criteria(page: 2, perPage: 4, sort: 'slug'));

        $this->assertSame(6, $second->total);
        $this->assertSame(['broken', 'draft', 'future', 'hello-world'], array_column($first->rows, 'slug'));
        $this->assertSame(['same-day', 'second'], array_column($second->rows, 'slug'));
    }

    public function test_map_sees_the_row_as_read_and_its_columns_are_flattened_after(): void
    {
        $seen = [];
        $source = new ContentSource(
            new ContentDirectory($this->dir, new LineFrontMatter),
            columns: ['slug', 'title', 'first_tag', 'published'],
            map: static function (array $row) use (&$seen): array {
                $seen[$row['slug']] = $row;

                return [...$row, 'first_tag' => $row['tags'][0] ?? null, 'published' => $row['date']];
            },
        );

        $row = $source->find('hello-world');

        $this->assertInstanceOf(DateTimeImmutable::class, $seen['hello-world']['date']);
        $this->assertSame(['hydra', 'php'], $seen['hello-world']['tags']);
        $this->assertInstanceOf(DateTimeImmutable::class, $seen['hello-world']['modified_at']);
        $this->assertSame('hydra', $row['first_tag'] ?? null);
        $this->assertSame('2026-10-01T00:00:00+00:00', $row['published'] ?? null);
    }

    public function test_map_is_not_asked_about_a_broken_file(): void
    {
        $seen = [];
        $source = new ContentSource(
            new ContentDirectory($this->dir, new LineFrontMatter),
            columns: ['slug'],
            map: static function (array $row) use (&$seen): array {
                $seen[] = $row['slug'];

                return $row;
            },
        );

        $source->page(new Criteria(perPage: 10));

        $this->assertNotContains('broken', $seen, 'it would meet a row without the keys it expects');
        $this->assertCount(5, $seen);
    }

    public function test_other_values_flatten_to_something_printable(): void
    {
        $this->post('odd', ['title: Odd', 'count: 3', 'flag: false'], 'x');
        $source = new ContentSource(
            new ContentDirectory($this->dir, new LineFrontMatter),
            columns: ['slug'],
            map: static fn (array $row): array => [...$row, 'nested' => ['a' => 1], 'mixed' => [1, ['x']]],
        );

        $row = $source->find('odd');

        $this->assertSame(3, $row['count'] ?? null);
        $this->assertFalse($row['flag'] ?? null);
        $this->assertSame('{"a":1}', $row['nested'] ?? null);
        $this->assertSame('[1,["x"]]', $row['mixed'] ?? null);
    }

    public function test_it_describes_itself_for_admin_check(): void
    {
        $description = $this->source()->describe();

        $this->assertInstanceOf(SourceDescription::class, $description);
        $this->assertSame($this->dir, $description->table);
        $this->assertSame(['id', 'slug', 'title', 'date', 'tags', 'status', 'body', 'modified_at', 'problem'], $description->columns);
        $this->assertSame(['slug', 'title', 'date'], $description->sortable);
        $this->assertSame(['title', 'tags', 'body'], $description->searchable);
        $this->assertSame(['date', 'tags', 'status'], $description->filterable);
        $this->assertSame('date', $description->defaultSort);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badDeclarations(): iterable
    {
        yield 'a sortable column it does not read' => [['sortable' => ['author']], 'sortable names "author"'];
        yield 'a searchable column it does not read' => [['searchable' => ['author']], 'searchable names "author"'];
        yield 'a filterable column it does not read' => [['filterable' => ['author']], 'filterable names "author"'];
        yield 'a default sort it does not read' => [['defaultSort' => 'author'], 'defaultSort names "author"'];
    }

    /** @param array<string, mixed> $declaration */
    #[DataProvider('badDeclarations')]
    public function test_a_declaration_it_cannot_honour_is_refused_where_it_is_written(array $declaration, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        new ContentSource(new ContentDirectory($this->dir, new LineFrontMatter), ...[
            'columns' => ['slug', 'title'],
            ...$declaration,
        ]);
    }

    public function test_a_posts_module_over_it_passes_admin_check(): void
    {
        $registry = new ModuleRegistry(
            new FakeContainer([
                PostsModule::class => new PostsModule,
                PostSource::class => new PostSource($this->dir, new DateTimeImmutable(self::NOW)),
            ]),
            [PostsModule::class],
        );

        $output = new FakeOutput;
        $code = (new AdminCheckCommand($registry))->execute(new ArrayInput, $output);

        $this->assertSame(ExitCode::Success, $code, implode("\n", $output->lines()));
    }

    public function test_the_posts_screens_list_filter_and_show(): void
    {
        $admin = new AdminHarness(
            [PostsModule::class => new PostsModule, PostSource::class => new PostSource($this->dir, new DateTimeImmutable(self::NOW))],
            [PostsModule::class],
        );

        $list = (string) $admin->controller->list($admin->request('GET', '/admin/posts'))->getBody();
        $php = (string) $admin->controller->list($admin->request('GET', '/admin/posts?tags=php'))->getBody();
        $show = $admin->controller->show($admin->request('GET', '/admin/posts/hello-world'));
        $broken = (string) $admin->controller->show($admin->request('GET', '/admin/posts/broken'))->getBody();

        $this->assertStringContainsString('Zebra crossing', $list);
        $this->assertStringContainsString('hydra, php', $list);
        $this->assertStringContainsString('2026-10-01', $list);
        $this->assertStringContainsString('not valid YAML', $list, 'the broken post is on the list, saying why');
        $this->assertStringNotContainsString('More words.', $list, 'no body on a list');
        $this->assertStringContainsString('Second thoughts', $php);
        $this->assertStringNotContainsString('Zebra crossing', $php);
        $this->assertSame(200, $show->getStatusCode());
        $this->assertStringContainsString('The first post, about the framework.', (string) $show->getBody());
        $this->assertStringContainsString('Still searchable body.', $broken);
    }

    public function test_the_always_there_columns_need_no_declaring(): void
    {
        $source = new ContentSource(
            new ContentDirectory($this->dir, new LineFrontMatter),
            columns: [],
            sortable: ['slug', 'modified_at'],
            searchable: ['body'],
            filterable: ['problem'],
            defaultSort: 'modified_at',
        );

        $this->assertSame(['id', 'slug', 'body', 'modified_at', 'problem'], $source->describe()->columns);
    }

    /**
     * @param list<string>|null $columns
     * @param list<string>|null $sortable
     * @param list<string>|null $searchable
     * @param list<string>|null $filterable
     */
    private function source(?array $columns = null, ?array $sortable = null, ?array $searchable = null, ?array $filterable = null): ContentSource
    {
        $now = new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));

        return new ContentSource(
            new ContentDirectory($this->dir, new LineFrontMatter),
            columns: $columns ?? self::COLUMNS,
            sortable: $sortable ?? ['slug', 'title', 'date'],
            searchable: $searchable ?? ['title', 'tags', 'body'],
            filterable: $filterable ?? ['date', 'tags', 'status'],
            defaultSort: 'date',
            map: static fn (array $row): array => [
                ...$row,
                'status' => match (true) {
                    ($row['draft'] ?? false) === true => 'draft',
                    ($row['date'] ?? null) > $now => 'scheduled',
                    default => 'published',
                },
            ],
        );
    }

    /** @param list<string> $meta */
    private function post(string $slug, array $meta, string $body): void
    {
        file_put_contents("{$this->dir}/{$slug}.md", "---\n" . implode("\n", $meta) . "\n---\n" . $body);
    }
}
