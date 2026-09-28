<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\ContentDisposition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentDisposition::class)]
final class ContentDispositionTest extends TestCase
{
    public function test_a_plain_name_is_sent_both_ways(): void
    {
        $this->assertSame(
            'attachment; filename="people.csv"; filename*=UTF-8\'\'people.csv',
            ContentDisposition::header(ContentDisposition::ATTACHMENT, 'people.csv'),
        );
    }

    public function test_inline_carries_a_name_too(): void
    {
        $this->assertSame(
            'inline; filename="My_Photo.png"; filename*=UTF-8\'\'My%20Photo.png',
            ContentDisposition::header(ContentDisposition::INLINE, 'My Photo.png'),
        );
    }

    public function test_unicode_is_transliterated_in_the_quoted_name_only(): void
    {
        $this->assertSame(
            'attachment; filename="r_sum_.pdf"; filename*=UTF-8\'\'r%C3%A9sum%C3%A9.pdf',
            ContentDisposition::header(ContentDisposition::ATTACHMENT, 'résumé.pdf'),
        );
    }

    public function test_a_name_cannot_carry_anything_out_of_the_header(): void
    {
        $this->assertSame(
            'attachment; filename="re_port_X-Evil_1.csv"; '
            . 'filename*=UTF-8\'\'re%22port%0D%0AX-Evil%3A%201.csv',
            ContentDisposition::header(ContentDisposition::ATTACHMENT, "re\"port\r\nX-Evil: 1.csv"),
        );
    }

    public function test_a_name_with_nothing_usable_still_has_one(): void
    {
        $this->assertStringContainsString(
            'filename="download"',
            ContentDisposition::header(ContentDisposition::ATTACHMENT, '???'),
        );
    }

    public function test_no_name_is_the_disposition_alone(): void
    {
        $this->assertSame('inline', ContentDisposition::header(ContentDisposition::INLINE));
        $this->assertSame('attachment', ContentDisposition::header(ContentDisposition::ATTACHMENT, ''));
    }

    public function test_anything_but_inline_or_attachment_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentDisposition::header("inline\r\nX-Evil: 1");
    }
}
