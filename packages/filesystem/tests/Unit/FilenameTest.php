<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Filesystem\Filename;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a person sees a stored file called. The key never comes from here; this
 * is only the name a download is saved under and a screen shows.
 */
#[CoversClass(Filename::class)]
final class FilenameTest extends TestCase
{
    #[DataProvider('names')]
    public function test_a_client_name_is_cleaned(?string $given, string $type, ?string $kept): void
    {
        $this->assertSame($kept, Filename::clean($given, $type));
    }

    /** @return iterable<string, array{?string, string, ?string}> */
    public static function names(): iterable
    {
        yield 'a plain name' => ['Quarterly Report.pdf', 'application/pdf', 'Quarterly Report.pdf'];
        yield 'unicode is kept' => ['résumé.pdf', 'application/pdf', 'résumé.pdf'];
        yield 'no name' => [null, 'image/png', null];
        yield 'an empty name' => ['', 'image/png', null];
        yield 'only whitespace' => ["  \t ", 'image/png', null];
        yield 'dot dot' => ['..', 'image/png', null];
        yield 'a unix path' => ['/home/me/photo.png', 'image/png', 'photo.png'];
        yield 'a windows path' => ['C:\\Users\\Me\\photo.png', 'image/png', 'photo.png'];
        yield 'a traversal' => ['../../etc/passwd.png', 'image/png', 'passwd.png'];
        yield 'control bytes' => ["re\r\nport\x00.pdf", 'application/pdf', 'report.pdf'];
        yield 'a C1 control' => ["a\u{85}b.pdf", 'application/pdf', 'ab.pdf'];
        yield 'invalid utf-8' => ["caf\xE9.pdf", 'application/pdf', "caf\u{FFFD}.pdf"];
        yield 'whitespace runs' => ["  my \t  holiday   photo.png ", 'image/png', 'my holiday photo.png'];
        yield 'leading dots' => ['.htaccess.png', 'image/png', 'htaccess.png'];
        yield 'trailing dots' => ['photo.png...', 'image/png', 'photo.png'];
        yield 'an uppercase extension' => ['PHOTO.PNG', 'image/png', 'PHOTO.PNG'];
        yield 'a jpeg alias' => ['photo.jpeg', 'image/jpeg', 'photo.jpeg'];
        yield 'a jpe alias' => ['photo.JPE', 'image/jpeg', 'photo.JPE'];
        yield 'a lying extension' => ['evil.php', 'image/png', 'evil.php.png'];
        yield 'no extension' => ['report', 'application/pdf', 'report.pdf'];
        yield 'a dotted stem' => ['Report v1.2', 'application/pdf', 'Report v1.2.pdf'];
        yield 'an unknown type forces nothing' => ['notes.md', 'application/x-unknown', 'notes.md'];
        yield 'text as .text' => ['notes.text', 'text/plain', 'notes.text'];
    }

    public function test_a_long_name_is_capped_in_bytes_and_keeps_its_extension(): void
    {
        $kept = Filename::clean(str_repeat('é', 200) . '.pdf', 'application/pdf');

        $this->assertNotNull($kept);
        $this->assertLessThanOrEqual(Filename::MAX_BYTES, strlen($kept));
        $this->assertStringEndsWith('.pdf', $kept);
        $this->assertSame(1, preg_match('//u', $kept), 'cut on a character boundary');
    }

    public function test_a_long_name_with_a_lying_extension_is_capped_after_the_fix(): void
    {
        $kept = Filename::clean(str_repeat('a', 300) . '.php', 'image/png');

        $this->assertNotNull($kept);
        $this->assertSame(Filename::MAX_BYTES, strlen($kept));
        $this->assertStringEndsWith('.png', $kept);
    }
}
