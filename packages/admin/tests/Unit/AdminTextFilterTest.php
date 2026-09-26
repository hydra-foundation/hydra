<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\ViewModels\ListViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A filter with no options to choose from, like an id, is typed rather than
 * picked, and keeps the value it was opened with.
 */
#[CoversClass(ListViewModel::class)]
final class AdminTextFilterTest extends TestCase
{
    public function test_a_filter_without_options_is_a_text_box_holding_its_value(): void
    {
        $body = $this->list('/admin/users?username=grace');

        $this->assertMatchesRegularExpression('#<input class="form-control" id="admin-filter-username" type="text" name="username"\s+value="grace"#', $body);
        $this->assertStringNotContainsString('<select class="form-select" id="admin-filter-username"', $body);
    }

    public function test_a_filter_with_options_is_still_a_select(): void
    {
        $this->assertStringContainsString('<select class="form-select" id="admin-filter-role"', $this->list('/admin/users'));
    }

    private function list(string $path): string
    {
        $module = new class implements ModuleInterface {
            public function define(): Definition
            {
                return Definition::make('users')
                    ->source(CrudUserSource::class)
                    ->fields(
                        Field::id(),
                        Field::text('username')->filterable(),
                        Field::select('role', ['admin' => 'Admin', 'user' => 'User'])->filterable(),
                    );
            }
        };
        $admin = new AdminHarness([$module::class => $module, CrudUserSource::class => new CrudUserSource], [$module::class]);

        return (string) $admin->controller->list($admin->request('GET', $path))->getBody();
    }
}
