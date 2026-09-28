<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Field;
use Hydra\Admin\FieldType;
use Hydra\Admin\FileUrls;
use Hydra\Admin\Surface;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\View\HtmlView;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A stored file read back as what it was called: a download link for any
 * file, and the description of an image.
 */
#[CoversClass(Field::class)]
#[CoversClass(FileUrls::class)]
final class FileFieldTest extends TestCase
{
    private const KEY = 'private:files/0123456789abcdef0123456789abcdef.pdf';

    private TemporaryDisks $disks;
    private FileUrls $urls;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->urls = new FileUrls('/admin', $this->disks->disks);
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_a_file_field_is_its_own_type(): void
    {
        $this->assertSame(FieldType::File, Field::file('attachment')->type());
    }

    public function test_a_private_url_carries_the_name_to_download_under(): void
    {
        $this->assertSame(
            '/admin/file?key=' . rawurlencode(self::KEY) . '&name=Quarterly%20Report.pdf',
            $this->urls->url(self::KEY, 'Quarterly Report.pdf'),
        );
    }

    public function test_a_public_url_is_its_key_alone_whatever_the_name(): void
    {
        $this->assertSame('/storage/blog/a.png', $this->urls->url('public:blog/a.png', 'Cover.png'));
    }

    public function test_it_links_to_the_file_under_its_kept_name(): void
    {
        $html = $this->html(
            Field::file('attachment')->nameFrom('attachment_name'),
            ['attachment' => self::KEY, 'attachment_name' => 'Quarterly Report.pdf'],
        );

        $this->assertStringContainsString(
            'href="' . htmlspecialchars('/admin/file?key=' . rawurlencode(self::KEY) . '&name=Quarterly%20Report.pdf') . '"',
            $html,
        );
        $this->assertStringContainsString('Quarterly Report.pdf</a>', $html);
    }

    public function test_with_no_kept_name_it_reads_as_its_key(): void
    {
        $html = $this->html(Field::file('attachment')->nameFrom('attachment_name'), ['attachment' => self::KEY]);

        $this->assertStringContainsString(basename(self::KEY) . '</a>', $html);
    }

    public function test_a_kept_name_is_escaped(): void
    {
        $html = $this->html(
            Field::file('attachment')->nameFrom('attachment_name'),
            ['attachment' => self::KEY, 'attachment_name' => '<script>.pdf'],
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;.pdf', $html);
    }

    public function test_an_export_carries_the_key(): void
    {
        $this->assertSame(
            self::KEY,
            Field::file('attachment')->nameFrom('attachment_name')
                ->display(Surface::Export, ['attachment' => self::KEY, 'attachment_name' => 'a.pdf'], files: $this->urls),
        );
    }

    public function test_no_file_is_nothing(): void
    {
        $this->assertSame('', Field::file('attachment')->display(Surface::List, ['attachment' => null], files: $this->urls));
    }

    public function test_a_value_with_no_url_is_shown_as_text(): void
    {
        $this->assertSame('../x', Field::file('attachment')->display(Surface::List, ['attachment' => '../x'], files: $this->urls));
    }

    public function test_an_image_is_described_by_its_kept_name(): void
    {
        $html = $this->html(
            Field::image('avatar')->nameFrom('avatar_name'),
            ['avatar' => 'private:avatars/0123456789abcdef0123456789abcdef.png', 'avatar_name' => 'Me "at" work.png'],
        );

        $this->assertStringContainsString('alt="Me &quot;at&quot; work.png"', $html);
    }

    public function test_only_an_image_or_a_file_has_a_name_to_read(): void
    {
        $this->expectException(LogicException::class);

        Field::text('avatar')->nameFrom('avatar_name');
    }

    /** @param array<string, mixed> $row */
    private function html(Field $field, array $row): string
    {
        $shown = $field->display(Surface::Show, $row, files: $this->urls);
        $this->assertInstanceOf(HtmlView::class, $shown);

        return (string) $shown;
    }
}
