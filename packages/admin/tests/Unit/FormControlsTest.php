<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Field;
use Hydra\Admin\Flag;
use Hydra\Admin\Input;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\ToggledUsersModule;
use Hydra\Admin\ViewModels\FormViewModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The controls that are not a text box, rendered and submitted through the
 * package's own template. A checkbox is the whole reason this file exists:
 * unticked it submits nothing, so every other control can be tested by what it
 * sends and this one has to be tested by what it does not.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(Field::class)]
#[CoversClass(Flag::class)]
#[CoversClass(Input::class)]
#[CoversClass(FormViewModel::class)]
final class FormControlsTest extends TestCase
{
    private CrudUserSource $source;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->source = new CrudUserSource;
        $this->admin = new AdminHarness(
            [ToggledUsersModule::class => new ToggledUsersModule, CrudUserSource::class => $this->source],
            [ToggledUsersModule::class],
        );
    }

    public function test_a_switch_is_a_checkbox_wearing_a_class(): void
    {
        $body = $this->render('edit', 'GET', '/admin/users/1/edit');

        $this->assertStringContainsString('form-check form-switch', $body);
        $this->assertStringContainsString('role="switch"', $body);
        $this->assertStringContainsString('type="checkbox"', $body);
    }

    public function test_a_stored_flag_opens_the_form_ticked(): void
    {
        // Row 1 is active, row 5 is not, and both hold an int the way a driver
        // hands a TINYINT back.
        $this->assertStringContainsString('checked', $this->checkbox($this->render('edit', 'GET', '/admin/users/1/edit')));
        $this->assertStringNotContainsString('checked', $this->checkbox($this->render('edit', 'GET', '/admin/users/5/edit')));
    }

    public function test_a_checkbox_carries_a_hidden_zero_so_it_can_be_cleared(): void
    {
        $body = $this->render('edit', 'GET', '/admin/users/1/edit');

        $this->assertStringContainsString('<input type="hidden" name="active" value="0">', $body);
    }

    public function test_ticking_the_box_stores_one(): void
    {
        $this->handle('update', 'POST', '/admin/users/5/edit', [], ['username' => 'barbara', 'active' => '1']);

        $this->assertSame('1', $this->source->find('5')['active'] ?? null);
    }

    public function test_unticking_the_box_stores_zero(): void
    {
        // What the browser actually sends for an unticked box: the hidden
        // companion alone. Absence has to read as off, not as "leave it".
        $this->handle('update', 'POST', '/admin/users/1/edit', [], ['username' => 'ada', 'active' => '0']);

        $this->assertSame('0', $this->source->find('1')['active'] ?? null);
    }

    public function test_a_box_that_submits_nothing_at_all_still_stores_zero(): void
    {
        // The hidden field is markup, and markup can be stripped. A submission
        // naming no flag must not be read as the flag it used to have.
        $this->handle('update', 'POST', '/admin/users/1/edit', [], ['username' => 'ada']);

        $this->assertSame('0', $this->source->find('1')['active'] ?? null);
    }

    public function test_radios_render_one_control_per_option_with_the_stored_one_chosen(): void
    {
        $group = $this->radioGroup($this->render('edit', 'GET', '/admin/users/1/edit'));

        $this->assertSame(2, substr_count($group, 'type="radio"'));
        $this->assertStringContainsString('value="admin"', $group);
        $this->assertStringContainsString('value="user"', $group);
        // Row 1 is an admin, and only that one may come back chosen.
        $this->assertSame(1, substr_count($group, 'checked'));
    }

    public function test_a_radio_group_is_labelled_by_reference_not_by_pointing(): void
    {
        // A label's for= names one control, and a group of radios is not one.
        $body = $this->render('edit', 'GET', '/admin/users/1/edit');

        $this->assertStringContainsString('aria-labelledby="field-role-label"', $body);
        $this->assertStringNotContainsString('for="field-role"', $body);
    }

    public function test_a_radio_submits_the_option_it_names(): void
    {
        $this->handle('update', 'POST', '/admin/users/1/edit', [], ['username' => 'ada', 'role' => 'user']);

        $this->assertSame('user', $this->source->find('1')['role'] ?? null);
    }

    public function test_a_boolean_field_reads_the_flag_back_in_words(): void
    {
        $body = $this->render('list', 'GET', '/admin/users');

        $this->assertStringContainsString('Enabled', $body);
        $this->assertStringContainsString('Suspended', $body);
    }

    private function radioGroup(string $body): string
    {
        $start = strpos($body, '<div role="radiogroup"');

        $this->assertNotFalse($start, 'the form rendered no radio group');

        return substr($body, $start, (int) strpos($body, 'admin-form-actions', $start) - $start);
    }

    private function checkbox(string $body): string
    {
        $start = strpos($body, 'type="checkbox"');

        $this->assertNotFalse($start, 'the form rendered no checkbox');

        return substr($body, $start, (int) strpos($body, '>', $start) - $start);
    }

    /** @param array<string, string> $headers */
    private function render(string $action, string $method, string $path, array $headers = []): string
    {
        return (string) $this->handle($action, $method, $path, $headers)->getBody();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $body
     */
    private function handle(string $action, string $method, string $path, array $headers = [], array $body = []): ResponseInterface
    {
        return $this->admin->controller->{$action}($this->admin->request($method, $path, $headers, $body));
    }
}
