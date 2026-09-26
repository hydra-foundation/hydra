<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Page;
use Hydra\Admin\Tests\Support\AdminHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** What a source can say about the rows it gave, beside the count under the table. */
#[CoversClass(Page::class)]
final class PageNoteTest extends TestCase
{
    public function test_a_note_sits_beside_the_count(): void
    {
        $body = $this->list([['id' => 1]], 'Only the last 2 MB is read.');

        $this->assertMatchesRegularExpression('#Showing 1–1 of 1\s*&middot;\s*Only the last 2 MB is read\.#', $body);
    }

    public function test_an_empty_page_can_say_why(): void
    {
        $body = $this->list([], 'Logs go to <stderr>.');

        $this->assertMatchesRegularExpression('#No results\s*&middot;\s*Logs go to &lt;stderr&gt;\.#', $body);
    }

    public function test_without_a_note_the_count_stands_alone(): void
    {
        $this->assertStringNotContainsString('&middot;', $this->list([['id' => 1]], null));
    }

    /** @param list<array<string, mixed>> $rows */
    private function list(array $rows, ?string $note): string
    {
        $source = new class ($rows, $note) implements SourceInterface {
            /** @param list<array<string, mixed>> $rows */
            public function __construct(private readonly array $rows, private readonly ?string $note) {}

            public function page(Criteria $criteria): Page
            {
                return new Page($this->rows, count($this->rows), $criteria, $this->note);
            }
        };
        $module = new class implements ModuleInterface {
            public function define(): Definition
            {
                return Definition::make('things')->source('things.source')->fields(Field::id());
            }
        };
        $admin = new AdminHarness([$module::class => $module, 'things.source' => $source], [$module::class]);

        return (string) $admin->controller->list($admin->request('GET', '/admin/things'))->getBody();
    }
}
