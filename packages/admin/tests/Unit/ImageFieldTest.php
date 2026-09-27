<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Field;
use Hydra\Admin\FieldType;
use Hydra\Admin\FileUrls;
use Hydra\Admin\Surface;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Uploads;
use Hydra\View\HtmlView;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Field::class)]
#[CoversClass(FileUrls::class)]
final class ImageFieldTest extends TestCase
{
    private const KEY = 'private:avatars/0123456789abcdef0123456789abcdef.png';

    private TemporaryDisks $disks;
    private FileUrls $urls;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->urls = new FileUrls('/admin', $this->disks->disks);
    }

    public function test_a_private_key_is_fetched_through_the_admin(): void
    {
        $this->assertSame('/admin/files?key=' . rawurlencode(self::KEY), $this->urls->url(self::KEY));
    }

    public function test_a_public_key_is_fetched_from_the_web_server(): void
    {
        $this->assertSame('/storage/blog/a.png', $this->urls->url('public:blog/a.png'));
    }

    public function test_a_key_that_is_not_one_has_no_url(): void
    {
        $this->assertNull($this->urls->url('../../.env'));
        $this->assertNull($this->urls->url('s3:blog/a.png'));
        $this->assertNull($this->urls->url(''));
    }

    public function test_without_disks_only_a_private_key_has_a_url(): void
    {
        $urls = new FileUrls('/admin');

        $this->assertSame('/admin/files?key=' . rawurlencode(self::KEY), $urls->url(self::KEY));
        $this->assertNull($urls->url('public:blog/a.png'));
    }

    public function test_an_image_is_told_apart_by_the_extension_its_key_was_given(): void
    {
        $this->assertTrue($this->urls->isImage(self::KEY));
        $this->assertFalse($this->urls->isImage('private:files/a.pdf'));
        $this->assertFalse($this->urls->isImage('private:files/a.bin'));
    }

    public function test_an_image_field_is_its_own_type(): void
    {
        $this->assertSame(FieldType::Image, Field::image('avatar')->type());
    }

    public function test_it_renders_an_img_at_the_file_url(): void
    {
        $html = $this->html(Field::image('avatar'), Surface::List, ['avatar' => self::KEY]);

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="/admin/files?key=' . htmlspecialchars(rawurlencode(self::KEY)) . '"', $html);
        $this->assertStringContainsString('class="admin-thumb"', $html);
        $this->assertStringContainsString('alt=""', $html);
    }

    public function test_the_detail_screen_shows_it_larger(): void
    {
        $this->assertStringContainsString('class="admin-image"', $this->html(Field::image('avatar'), Surface::Show, ['avatar' => self::KEY]));
    }

    public function test_an_export_carries_the_key_as_text(): void
    {
        $this->assertSame(self::KEY, Field::image('avatar')->display(Surface::Export, ['avatar' => self::KEY], files: $this->urls));
    }

    public function test_no_file_renders_the_fallback_icon(): void
    {
        $html = $this->html(Field::image('avatar')->fallbackIcon('person-circle'), Surface::List, ['avatar' => null]);

        $this->assertStringContainsString('bi bi-person-circle', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_no_file_and_no_fallback_renders_nothing(): void
    {
        $this->assertSame('', Field::image('avatar')->display(Surface::List, ['avatar' => ''], files: $this->urls));
    }

    public function test_a_placeholder_still_wins_over_the_icon(): void
    {
        $shown = Field::image('avatar')->fallbackIcon('person-circle')->emptyAs('—')
            ->display(Surface::List, ['avatar' => null], files: $this->urls);

        $this->assertSame('—', $shown);
    }

    public function test_the_fallback_icon_name_cannot_break_out_of_its_attribute(): void
    {
        $this->expectException(LogicException::class);

        Field::image('avatar')->fallbackIcon('x" onmouseover="alert(1)');
    }

    public function test_a_fallback_icon_is_an_image_field_s_alone(): void
    {
        $this->expectException(LogicException::class);

        Field::text('avatar')->fallbackIcon('person-circle');
    }

    public function test_a_value_with_no_url_is_shown_as_text_rather_than_as_a_broken_image(): void
    {
        $shown = Field::image('avatar')->display(Surface::List, ['avatar' => '"><script>'], files: $this->urls);

        $this->assertSame('"><script>', $shown);
    }

    public function test_the_list_and_detail_screens_render_the_image(): void
    {
        $source = new CrudUserSource;
        $source->update('2', ['avatar' => self::KEY]);
        $admin = new AdminHarness(
            [AvatarUsersModule::class => new AvatarUsersModule, CrudUserSource::class => $source],
            [AvatarUsersModule::class],
            uploads: new Uploads($this->disks->disks),
        );

        $list = (string) $admin->controller->list($admin->request('GET', '/admin/users'))->getBody();
        $show = (string) $admin->controller->show($admin->request('GET', '/admin/users/2'))->getBody();

        $this->assertStringContainsString('class="admin-thumb"', $list);
        $this->assertStringContainsString('bi bi-person-circle', $list);
        $this->assertStringContainsString('class="admin-image"', $show);
    }

    public function test_the_edit_form_previews_the_stored_image(): void
    {
        $source = new CrudUserSource;
        $source->update('2', ['avatar' => self::KEY]);
        $admin = new AdminHarness(
            [AvatarUsersModule::class => new AvatarUsersModule, CrudUserSource::class => $source],
            [AvatarUsersModule::class],
            uploads: new Uploads($this->disks->disks),
        );

        $form = (string) $admin->controller->edit($admin->request('GET', '/admin/users/2/edit'))->getBody();

        $this->assertStringContainsString('src="/admin/files?key=' . htmlspecialchars(rawurlencode(self::KEY)) . '"', $form);
    }

    /** @param array<string, mixed> $row */
    private function html(Field $field, Surface $surface, array $row): string
    {
        $shown = $field->display($surface, $row, files: $this->urls);
        $this->assertInstanceOf(HtmlView::class, $shown);

        return (string) $shown;
    }
}
