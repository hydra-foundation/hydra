<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Input;
use Hydra\Admin\InputType;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\ToggledUsersModule;
use Hydra\Admin\ViewModels\FormViewModel;
use Hydra\Validation\Rules\MaxFileSize;
use Hydra\Validation\Rules\MimeType;
use Hydra\Validation\Rules\Nullable;
use Hydra\Validation\Rules\UploadedFile;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Input::class)]
#[CoversClass(FormViewModel::class)]
final class FileInputTest extends TestCase
{
    public function test_a_file_control_is_stored_in_a_directory_named_after_it_unless_told(): void
    {
        $this->assertSame(InputType::File, Input::file('avatar')->type());
        $this->assertSame('avatar', Input::file('avatar')->directory());
        $this->assertSame('avatars', Input::file('avatar')->storedIn('avatars')->directory());
    }

    public function test_a_file_is_private_unless_stored_publicly(): void
    {
        $this->assertFalse(Input::file('avatar')->isPublic());
        $this->assertTrue(Input::file('cover')->storedIn('blog', public: true)->isPublic());
    }

    public function test_accepts_and_max_size_become_rules_behind_the_upload_check(): void
    {
        $rules = Input::file('avatar')->accepts('image/png')->maxSize(1024)->ruleSet();

        $this->assertInstanceOf(Nullable::class, $rules[0]);
        $this->assertInstanceOf(UploadedFile::class, $rules[1]);
        $this->assertInstanceOf(MimeType::class, $rules[2]);
        $this->assertInstanceOf(MaxFileSize::class, $rules[3]);
    }

    public function test_the_accepted_types_are_kept_for_the_browser(): void
    {
        $this->assertSame(['image/png', 'image/jpeg'], Input::file('avatar')->accepts('image/png', 'image/jpeg')->accepted());
    }

    public function test_a_file_control_is_not_removable_unless_it_says_so(): void
    {
        $this->assertFalse(Input::file('avatar')->isRemovable());
        $this->assertTrue(Input::file('avatar')->removable()->isRemovable());
        $this->assertSame('avatar_remove', Input::file('avatar')->removeName());
    }

    public function test_a_file_control_keeps_no_name_unless_it_says_so(): void
    {
        $this->assertNull(Input::file('avatar')->nameColumn());
        $this->assertSame('avatar_name', Input::file('avatar')->keepsName()->nameColumn());
        $this->assertSame('avatar_filename', Input::file('avatar')->keepsName('avatar_filename')->nameColumn());
    }

    public function test_the_name_cannot_go_in_the_column_that_holds_the_key(): void
    {
        $this->expectException(LogicException::class);

        Input::file('avatar')->keepsName('avatar');
    }

    #[DataProvider('fileOnly')]
    public function test_a_file_modifier_on_another_control_is_a_mistake(callable $modify): void
    {
        $this->expectException(LogicException::class);

        $modify(Input::text('avatar'));
    }

    /** @return iterable<string, array{callable(Input): Input}> */
    public static function fileOnly(): iterable
    {
        yield 'storedIn' => [static fn (Input $input): Input => $input->storedIn('avatars')];
        yield 'accepts' => [static fn (Input $input): Input => $input->accepts('image/png')];
        yield 'maxSize' => [static fn (Input $input): Input => $input->maxSize(10)];
        yield 'removable' => [static fn (Input $input): Input => $input->removable()];
        yield 'keepsName' => [static fn (Input $input): Input => $input->keepsName()];
    }

    public function test_a_directory_that_is_not_a_path_inside_the_disk_is_refused_on_declaration(): void
    {
        $this->expectException(LogicException::class);

        Input::file('avatar')->storedIn('../avatars');
    }

    public function test_a_form_with_a_file_control_posts_multipart(): void
    {
        $body = $this->render(AvatarUsersModule::class, '/admin/users/1/edit');

        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('hx-encoding="multipart/form-data"', $body);
    }

    public function test_a_form_without_one_posts_as_it_always_has(): void
    {
        $body = $this->render(ToggledUsersModule::class, '/admin/users/1/edit');

        $this->assertStringNotContainsString('multipart/form-data', $body);
    }

    public function test_the_control_tells_the_browser_what_to_offer(): void
    {
        $body = $this->render(AvatarUsersModule::class, '/admin/users/1/edit');

        $this->assertStringContainsString('type="file"', $body);
        $this->assertStringContainsString('name="avatar"', $body);
        $this->assertStringContainsString('accept="image/png,image/jpeg"', $body);
        $this->assertStringContainsString('PNG or JPEG, up to 1 KB.', $body);
    }

    public function test_the_remove_box_appears_only_when_there_is_something_to_remove(): void
    {
        $source = new CrudUserSource;
        $source->update('1', ['avatar' => 'private:avatars/' . str_repeat('a', 32) . '.png']);

        $this->assertStringContainsString('name="avatar_remove"', $this->render(AvatarUsersModule::class, '/admin/users/1/edit', $source));
        $this->assertStringNotContainsString('name="avatar_remove"', $this->render(AvatarUsersModule::class, '/admin/users/2/edit', $source));
    }

    public function test_the_create_form_has_nothing_to_remove(): void
    {
        $this->assertStringNotContainsString('avatar_remove', $this->render(AvatarUsersModule::class, '/admin/users/new', action: 'create'));
    }

    /** @param class-string<AvatarUsersModule|ToggledUsersModule> $module */
    private function render(string $module, string $path, ?CrudUserSource $source = null, string $action = 'edit'): string
    {
        $admin = new AdminHarness(
            [$module => new $module, CrudUserSource::class => $source ?? new CrudUserSource],
            [$module],
        );

        return (string) $admin->controller->{$action}($admin->request('GET', $path))->getBody();
    }
}
