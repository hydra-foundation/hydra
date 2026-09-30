<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit\Notifications;

use Hydra\Admin\Notifications\Notice;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a notice may say, and where it may send the reader. */
#[CoversClass(Notice::class)]
final class NoticeTest extends TestCase
{
    public function test_a_title_alone_is_a_notice(): void
    {
        $notice = new Notice('Invoice paid');

        $this->assertSame(['Invoice paid', null, null, 'notice'], [$notice->title, $notice->body, $notice->url, $notice->kind]);
    }

    public function test_every_field(): void
    {
        $notice = new Notice('Invoice paid', 'Invoice #104 was paid.', '/admin/invoices/104', 'invoice.paid');

        $this->assertSame(['Invoice paid', 'Invoice #104 was paid.', '/admin/invoices/104', 'invoice.paid'], [$notice->title, $notice->body, $notice->url, $notice->kind]);
    }

    /** @return iterable<string, array{string}> */
    public static function goodUrls(): iterable
    {
        yield 'a path' => ['/admin/settings/security'];
        yield 'a path with a query' => ['/admin/users?page=2'];
        yield 'https' => ['https://example.com/a'];
        yield 'http' => ['http://example.com/a'];
    }

    #[DataProvider('goodUrls')]
    public function test_a_path_or_a_web_address_is_a_link(string $url): void
    {
        $this->assertSame($url, (new Notice('x', url: $url))->url);
    }

    /** @return iterable<string, array{string, string|null, string|null, string, string}> */
    public static function refused(): iterable
    {
        yield 'an empty title' => ['', null, null, 'notice', 'A notice needs a title'];
        yield 'a blank title' => ['   ', null, null, 'notice', 'A notice needs a title'];
        yield 'a long title' => [str_repeat('t', 256), null, null, 'notice', 'at most 255 characters'];
        yield 'a long body' => ['x', str_repeat('b', 2001), null, 'notice', 'at most 2000 characters'];
        yield 'javascript' => ['x', null, 'javascript:alert(1)', 'notice', 'is not a link a notice may carry'];
        yield 'a protocol-relative url' => ['x', null, '//evil.example/a', 'notice', 'is not a link a notice may carry'];
        yield 'a relative path' => ['x', null, 'admin/users', 'notice', 'is not a link a notice may carry'];
        yield 'data' => ['x', null, 'data:text/html,hi', 'notice', 'is not a link a notice may carry'];
        yield 'a long url' => ['x', null, '/' . str_repeat('a', 2048), 'notice', 'is not a link a notice may carry'];
        yield 'a bad kind' => ['x', null, null, 'Two Factor', 'is not a notice kind'];
        yield 'an empty kind' => ['x', null, null, '', 'is not a notice kind'];
    }

    #[DataProvider('refused')]
    public function test_it_is_refused(string $title, ?string $body, ?string $url, string $kind, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Notice($title, $body, $url, $kind);
    }
}
