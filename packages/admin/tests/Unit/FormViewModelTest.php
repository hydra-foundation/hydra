<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Blueprint;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Tests\Support\ArraySource;
use Hydra\Admin\ViewModels\FormViewModel;
use PHPUnit\Framework\TestCase;

final class FormViewModelTest extends TestCase
{
    public function test_it_posts_back_to_the_url_the_form_is_at(): void
    {
        $vm = $this->viewModel('42');

        $this->assertSame('/admin/users/42/edit', $vm->action());
        $this->assertSame('/admin/users', $vm->cancelUrl());
    }

    public function test_an_id_needing_encoding_survives_the_url(): void
    {
        $this->assertSame('/admin/users/a%2Fb/edit', $this->viewModel('a/b')->action());
    }

    public function test_a_create_form_posts_to_a_url_that_names_no_row(): void
    {
        $vm = new FormViewModel(
            $this->blueprint(),
            FormScreen::create()->inputs(Input::text('username')),
            null,
            '/admin',
        );

        $this->assertSame('/admin/users/new', $vm->action());
        $this->assertSame('/admin/users', $vm->cancelUrl());
        $this->assertFalse($vm->canApply());
        $this->assertTrue($this->viewModel('42')->canApply());
    }

    public function test_it_fills_controls_from_the_values_it_was_given(): void
    {
        $vm = $this->viewModel('42', ['username' => 'ada', 'password' => 'hunter2']);
        [$username, $password] = $vm->controls();

        $this->assertSame('ada', $vm->value($username));
        $this->assertSame('', $vm->value($password));
    }

    public function test_errors_are_reported_per_control(): void
    {
        $vm = $this->viewModel('42', ['username' => ''], ['username' => 'Enter a username.']);

        $this->assertTrue($vm->hasErrors());
        $this->assertTrue($vm->hasError('username'));
        $this->assertSame('Enter a username.', $vm->error('username'));
        $this->assertFalse($vm->hasError('password'));
    }

    public function test_an_error_with_no_control_shows_above_the_form(): void
    {
        $vm = $this->viewModel('42', [], ['username' => 'Taken.', 'row' => 'Someone moved it.']);

        $this->assertSame(['Someone moved it.'], $vm->formErrors());
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    private function viewModel(string $id, array $values = [], array $errors = []): FormViewModel
    {
        $blueprint = $this->blueprint();
        $screen = $blueprint->screen('edit');
        $this->assertInstanceOf(FormScreen::class, $screen);

        return new FormViewModel($blueprint, $screen, $id, '/admin', $values, $errors);
    }

    private function blueprint(): Blueprint
    {
        return Definition::make('users')
            ->source(new ArraySource)
            ->fields(Field::id(), Field::text('username'))
            ->screens(FormScreen::edit()->inputs(
                Input::text('username')->required(),
                Input::password('password'),
            ))
            ->compile();
    }
}
