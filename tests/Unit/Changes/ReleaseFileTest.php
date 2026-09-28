<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\FrontMatter;
use Hydra\Tools\Changes\InvalidChangeFile;
use Hydra\Tools\Changes\ReleaseFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseFile::class)]
#[CoversClass(FrontMatter::class)]
#[CoversClass(InvalidChangeFile::class)]
final class ReleaseFileTest extends TestCase
{
    private const FILE = 'changes/0.9.17.md';

    public function test_a_release_file_carries_its_fields_and_body(): void
    {
        $release = ReleaseFile::parse("---\nversion: 0.9.17\ntitle: Faces\ndate: 2026-09-28\nsecurity: true\n---\nIntro.\n\n## The admin\n\n- A note.\n", self::FILE);

        $this->assertSame('0.9.17', $release->version);
        $this->assertSame('Faces', $release->title);
        $this->assertSame('2026-09-28', $release->date);
        $this->assertTrue($release->security);
        $this->assertSame("Intro.\n\n## The admin\n\n- A note.", $release->body);
    }

    public function test_security_defaults_to_false(): void
    {
        $this->assertFalse(ReleaseFile::parse("---\nversion: 0.9.17\ntitle: Faces\ndate: 2026-09-28\n---\nIntro.\n", self::FILE)->security);
    }

    public function test_it_writes_itself_back_out(): void
    {
        $release = new ReleaseFile('0.9.17', 'Faces', '2026-09-28', false, "Intro.\n\n## The admin\n\n- A note.");

        $text = $release->render();

        $this->assertSame("---\nversion: 0.9.17\ntitle: Faces\ndate: 2026-09-28\nsecurity: false\n---\nIntro.\n\n## The admin\n\n- A note.\n", $text);
        $this->assertEquals($release, ReleaseFile::parse($text, self::FILE));
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function invalid(): iterable
    {
        $ok = ['version: 0.9.17', 'title: Faces', 'date: 2026-09-28'];

        yield 'missing title' => [self::FILE, "---\nversion: 0.9.17\ndate: 2026-09-28\n---\n", 1, "missing 'title'"];
        yield 'bad version' => ['changes/0.9.md', "---\nversion: 0.9\ntitle: Faces\ndate: 2026-09-28\n---\n", 2, "'0.9' is not a version"];
        yield 'file named for another version' => ['changes/0.9.16.md', "---\n" . implode("\n", $ok) . "\n---\n", 2, 'holds 0.9.17 but is named for 0.9.16'];
        yield 'bad date' => [self::FILE, "---\nversion: 0.9.17\ntitle: Faces\ndate: 2026-02-30\n---\n", 4, "'2026-02-30' is not a date"];
        yield 'bad security' => [self::FILE, "---\n" . implode("\n", $ok) . "\nsecurity: yes\n---\n", 5, "security must be true or false, not 'yes'"];
        yield 'unknown key' => [self::FILE, "---\n" . implode("\n", $ok) . "\ncodename: Faces\n---\n", 5, "unknown key 'codename'"];
    }

    #[DataProvider('invalid')]
    public function test_an_invalid_release_file_names_the_file_and_line(string $file, string $text, int $line, string $message): void
    {
        try {
            ReleaseFile::parse($text, $file);
            $this->fail('Expected InvalidChangeFile');
        } catch (InvalidChangeFile $e) {
            $this->assertSame($file, $e->path);
            $this->assertSame($line, $e->lineNumber);
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_an_unwritten_intro_is_refused(): void
    {
        $release = new ReleaseFile('0.9.17', 'Faces', '2026-09-28', false, ReleaseFile::PLACEHOLDER_INTRO . "\n\n## The admin\n\n- A note.");

        $this->expectException(InvalidChangeFile::class);
        $this->expectExceptionMessage(self::FILE . ':7: the intro is still the placeholder');

        ReleaseFile::parse($release->render(), self::FILE);
    }

    public function test_the_constructor_refuses_what_parse_would(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'1.0' is not a version");

        new ReleaseFile('1.0', 'Faces', '2026-09-28', false, '');
    }

    public function test_it_reads_from_disk(): void
    {
        $dir = sys_get_temp_dir() . '/hydra-release-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/0.9.17.md", "---\nversion: 0.9.17\ntitle: Faces\ndate: 2026-09-28\n---\nIntro.\n");

        try {
            $this->assertSame('Faces', ReleaseFile::fromFile("$dir/0.9.17.md")->title);
        } finally {
            unlink("$dir/0.9.17.md");
            rmdir($dir);
        }
    }
}
