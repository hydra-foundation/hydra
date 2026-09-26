<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\CrudUsersModule;
use Hydra\Admin\Tests\Support\GoneUsersModule;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\HtmxResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A list is a snapshot, and a row on it can be gone by the time its link is
 * followed. A module that expects that says so, and gets the list back.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(Definition::class)]
final class AdminGoneRowTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function rowScreens(): iterable
    {
        yield 'show' => ['GET', '/admin/users/99'];
        yield 'edit' => ['GET', '/admin/users/99/edit'];
        yield 'update' => ['POST', '/admin/users/99/edit'];
    }

    #[DataProvider('rowScreens')]
    public function test_a_gone_row_brings_the_list_back_saying_why(string $method, string $path): void
    {
        $admin = $this->harness(GoneUsersModule::class);

        $response = $this->call($admin, $method, $path, $admin->frame());
        $body = (string) $response->getBody();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('That user has already left.', $body);
        $this->assertStringContainsString('/admin/users/1', $body);
        $this->assertSame('/admin/users', HtmxResponse::directive($response, 'push-url'));
    }

    public function test_without_htmx_the_list_is_the_whole_page(): void
    {
        $admin = $this->harness(GoneUsersModule::class);

        $response = $admin->controller->show($admin->request('GET', '/admin/users/99'));
        $body = (string) $response->getBody();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringStartsWith('<!doctype html>', $body);
        $this->assertStringContainsString('That user has already left.', $body);
    }

    public function test_a_module_that_says_nothing_is_still_not_found(): void
    {
        $admin = $this->harness(CrudUsersModule::class);

        $this->expectException(NotFoundException::class);

        $admin->controller->show($admin->request('GET', '/admin/users/99', $admin->frame()));
    }

    /** @param array<string, string> $headers */
    private function call(AdminHarness $admin, string $method, string $path, array $headers): ResponseInterface
    {
        $request = $admin->request($method, $path, $headers, $method === 'POST' ? ['username' => 'grace'] : []);

        return match (true) {
            $method === 'POST' => $admin->controller->update($request),
            str_ends_with($path, '/edit') => $admin->controller->edit($request),
            default => $admin->controller->show($request),
        };
    }

    /** @param class-string<ModuleInterface> $module */
    private function harness(string $module): AdminHarness
    {
        return new AdminHarness(
            [$module => new $module, CrudUserSource::class => new CrudUserSource],
            [$module],
        );
    }
}
