<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\Fragment;
use Hydra\Tools\Changes\ReleaseFile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * bin/changes.php, driven over a throwaway changes/ directory.
 */
#[CoversNothing]
final class ChangesScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../bin/changes.php';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-changes-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/unreleased", 0777, true);
        touch("$this->dir/unreleased/.gitkeep");
    }

    protected function tearDown(): void
    {
        foreach ([...glob("$this->dir/unreleased/{,.}*", GLOB_BRACE) ?: [], ...glob("$this->dir/*.md") ?: []] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir("$this->dir/unreleased");
        rmdir($this->dir);
    }

    public function test_new_writes_a_template_that_check_refuses_until_filled_in(): void
    {
        [$status, $out] = $this->script(['new', 'file-uploads']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('unreleased/file-uploads.md', $out);
        $this->assertStringEqualsFile("$this->dir/unreleased/file-uploads.md", Fragment::template());

        [$status, , $err] = $this->script(['check']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('file-uploads.md:2: section is still the placeholder', $err);
    }

    public function test_new_takes_the_section_and_kind(): void
    {
        $this->script(['new', 'a-fix', '--section', 'Validation', '--kind=fixed']);

        $this->assertStringEqualsFile("$this->dir/unreleased/a-fix.md", Fragment::template('Validation', 'fixed'));
    }

    public function test_new_refuses_an_existing_slug_a_bad_slug_and_a_bad_kind(): void
    {
        $this->fragment('taken', 'The admin', 'added', 'Mine.');

        foreach ([
            [['new', 'taken'], 'already exists'],
            [['new', 'Not A Slug'], 'kebab-case'],
            [['new', 'ok', '--kind', 'new'], "'new' is not a kind"],
        ] as [$args, $message]) {
            [$status, , $err] = $this->script($args);
            $this->assertSame(1, $status, $message);
            $this->assertStringContainsString($message, $err);
        }

        $this->assertStringContainsString('Mine.', (string) file_get_contents("$this->dir/unreleased/taken.md"));
    }

    public function test_list_prints_the_notes_as_the_release_will_group_them(): void
    {
        [$status, $out] = $this->script(['list']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('No unreleased notes', $out);

        $this->fragment('b-fix', 'The admin', 'fixed', 'A fix.');
        $this->fragment('a-gone', 'The admin', 'removed', 'A removal.');

        [$status, $out] = $this->script(['list']);
        $this->assertSame(0, $status);
        $this->assertSame("## The admin\n\n- **Removed.** A removal.\n- A fix.\n", $out);
    }

    public function test_release_collects_the_fragments_and_deletes_them(): void
    {
        $this->fragment('a-thing', 'The admin', 'added', 'A thing.', 'Do a thing.');
        $this->fragment('b-hole', 'The filesystem', 'security', 'A hole.');

        [$status, $out] = $this->script(['release', '0.9.17', '--title', 'Faces', '--no-edit']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('changes/0.9.17.md', str_replace($this->dir, 'changes', $out));
        $this->assertStringContainsString('write the intro', $out);
        $this->assertSame(['.gitkeep'], array_values(array_diff(scandir("$this->dir/unreleased") ?: [], ['.', '..'])));

        $expected = new ReleaseFile('0.9.17', 'Faces', date('Y-m-d'), true, ReleaseFile::PLACEHOLDER_INTRO . "\n\n"
            . "## Upgrading\n\n- **The admin:** Do a thing.\n\n## The admin\n\n- A thing.\n\n## The filesystem\n\n- **Security.** A hole.");
        $this->assertStringEqualsFile("$this->dir/0.9.17.md", $expected->render());
    }

    public function test_release_opens_the_editor_on_the_file(): void
    {
        $this->fragment('a-thing', 'The admin', 'added', 'A thing.');
        $editor = sprintf("sed -i 's/^%s$/The one with faces./'", preg_quote(ReleaseFile::PLACEHOLDER_INTRO, '/'));

        [$status] = $this->script(['release', '0.9.17', '--title', 'Faces'], ['VISUAL' => $editor]);

        $this->assertSame(0, $status);
        $this->assertStringStartsWith('The one with faces.', ReleaseFile::fromFile("$this->dir/0.9.17.md")->body);
        $this->assertSame(0, $this->script(['check'])[0]);
    }

    public function test_release_refuses_rather_than_losing_anything(): void
    {
        foreach ([
            [['release', '0.9.17', '--title', 'Faces'], 'nothing to release'],
        ] as [$args, $message]) {
            [$status, , $err] = $this->script($args);
            $this->assertSame(1, $status);
            $this->assertStringContainsString($message, $err);
        }

        $this->fragment('a-thing', 'The admin', 'added', 'A thing.');
        file_put_contents("$this->dir/0.9.16.md", (new ReleaseFile('0.9.16', 'Old', '2026-09-01', false, 'Intro.'))->render());

        foreach ([
            [['release', '0.9.16', '--title', 'Again', '--no-edit'], '0.9.16.md already exists'],
            [['release', '0.9', '--title', 'Faces', '--no-edit'], "'0.9' is not a version"],
            [['release', '0.9.17', '--no-edit'], 'usage'],
        ] as [$args, $message]) {
            [$status, , $err] = $this->script($args);
            $this->assertSame(1, $status, $message);
            $this->assertStringContainsString($message, $err);
        }

        $this->assertFileExists("$this->dir/unreleased/a-thing.md");
        $this->assertStringContainsString('Old', (string) file_get_contents("$this->dir/0.9.16.md"));
    }

    public function test_release_refuses_while_a_fragment_is_broken(): void
    {
        file_put_contents("$this->dir/unreleased/broken.md", "---\nkind: added\n---\nNo section.\n");

        [$status, , $err] = $this->script(['release', '0.9.17', '--title', 'Faces', '--no-edit']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString("broken.md:1: missing 'section'", $err);
        $this->assertFileDoesNotExist("$this->dir/0.9.17.md");
    }

    public function test_check_names_every_bad_file(): void
    {
        $this->fragment('fine', 'The admin', 'added', 'Fine.');
        file_put_contents("$this->dir/unreleased/bad-kind.md", "---\nsection: A\nkind: new\n---\nA note.\n");
        file_put_contents("$this->dir/0.9.16.md", "---\nversion: 0.9.16\ntitle: Faces\n---\n");
        file_put_contents("$this->dir/0.9.15.md", (new ReleaseFile('0.9.15', 'Fine', '2026-09-01', false, 'Intro.'))->render());

        [$status, , $err] = $this->script(['check']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString("bad-kind.md:3: 'new' is not a kind", $err);
        $this->assertStringContainsString("0.9.16.md:1: missing 'date'", $err);
        $this->assertStringNotContainsString('fine.md', $err);
        $this->assertStringNotContainsString('0.9.15.md', $err);
    }

    public function test_check_passes_quietly_on_good_files(): void
    {
        $this->fragment('fine', 'The admin', 'added', 'Fine.');

        [$status, $out] = $this->script(['check']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('1 fragment and 0 release files parse', $out);
    }

    public function test_an_unknown_command_prints_usage(): void
    {
        [$status, , $err] = $this->script(['frobnicate']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('usage', $err);
    }

    private function fragment(string $slug, string $section, string $kind, string $note, ?string $upgrading = null): void
    {
        $body = $upgrading === null ? $note : "$note\n\n### Upgrading\n\n$upgrading";
        file_put_contents("$this->dir/unreleased/$slug.md", "---\nsection: $section\nkind: $kind\n---\n$body\n");
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{int, string, string}
     */
    private function script(array $args, array $env = []): array
    {
        $process = proc_open(
            ['php', self::SCRIPT, "--dir=$this->dir", ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['VISUAL' => 'false', 'EDITOR' => 'false'] + getenv(),
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
