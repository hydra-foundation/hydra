<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\Migrator;
use Hydra\Tools\Changes\ReleaseFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Migrator::class)]
final class MigratorTest extends TestCase
{
    private const CHANGELOG = __DIR__ . '/fixtures/changelog.html';

    public function test_each_release_heading_becomes_a_release_file(): void
    {
        $releases = $this->releases();

        $this->assertSame(['0.9.16', '0.5.0', '0.4.0'], array_map(fn (ReleaseFile $r) => $r->version, $releases));
        $this->assertSame(['Faces', 'Hardening pass', 'Naming & types’ rules'], array_map(fn (ReleaseFile $r) => $r->title, $releases));
        $this->assertSame(['2026-09-27', '2026-09-13', '2026-09-12'], array_map(fn (ReleaseFile $r) => $r->date, $releases));
        $this->assertSame([false, false, false], array_map(fn (ReleaseFile $r) => $r->security, $releases));
    }

    public function test_the_body_is_the_html_between_the_meta_line_and_the_next_heading(): void
    {
        $this->assertSame(<<<'HTML'
            <p>Until now, the admin could only take strings.</p>

            <h3>Upgrading</h3>

            <ul>
              <li>Register <code>FilesystemServiceProvider</code>.</li>
              <li>Run <code>storage:link</code>.</li>
            </ul>

            <h3>The admin</h3>

            <ul>
              <li><code>Input::file()</code> declares a file control.</li>
            </ul>
            HTML, $this->releases()[0]->body);
    }

    public function test_the_page_intro_and_earlier_are_not_a_release(): void
    {
        $bodies = implode("\n", array_map(fn (ReleaseFile $r) => $r->body, $this->releases()));

        $this->assertStringNotContainsString('On the numbers', $bodies);
        $this->assertStringNotContainsString('predate this log', $bodies);
        $this->assertStringNotContainsString('pager', $bodies);
        $this->assertStringNotContainsString('relmeta', $bodies);
        $this->assertStringEndsWith('<p>After the code.</p>', $this->releases()[2]->body);
    }

    public function test_code_is_left_alone_and_starts_its_own_markdown_block(): void
    {
        // Column-0 code lines must not stop the rest of the body being
        // dedented, and are not moved themselves. The blank line before
        // <pre> starts a markdown HTML block that runs to </pre>, so the
        // blank line inside the code no longer ends the <div>'s block and
        // drops what follows into markdown.
        $this->assertSame(<<<'HTML'
            <p>Four colliding types renamed.</p>

            <div class="code">
              <div class="code-bar">php<button class="copy">copy</button></div>

            <pre><code>$a = 1;

                $b = 2;</code></pre>
            </div>

            <p>After the code.</p>
            HTML, $this->releases()[2]->body);
    }

    public function test_a_link_to_another_release_on_the_page_points_at_its_own_page(): void
    {
        $body = $this->releases()[1]->body;

        $this->assertStringContainsString('<a href="0.4.0.html">0.4.0</a>', $body);
        $this->assertStringContainsString('<a href="0.9.16.html">a later one</a>', $body);
        $this->assertStringNotContainsString('#v', $body);
    }

    public function test_the_page_indent_is_removed_so_markdown_reads_no_code_blocks(): void
    {
        $body = $this->releases()[1]->body;

        $this->assertStringContainsString("\n<div class=\"tw\">\n<table>\n  <tbody>\n    <tr>", $body);
        $this->assertDoesNotMatchRegularExpression('/\n\n {4}/', $body, 'a line indented four spaces after a blank line is a code block in markdown');
    }

    public function test_every_heading_item_and_paragraph_lands_in_exactly_one_file(): void
    {
        $html = (string) file_get_contents(self::CHANGELOG);
        $bodies = array_map(fn (ReleaseFile $r) => $r->body, $this->releases());

        foreach (['h3', 'li', 'dt', 'dd', 'tr'] as $tag) {
            preg_match_all("#<$tag>.*?</$tag>#s", $html, $original);
            $found = array_merge(...array_map(static function (string $body) use ($tag): array {
                preg_match_all("#<$tag>.*?</$tag>#s", $body, $m);

                return $m[0];
            }, $bodies));

            $this->assertSame($original[0], $found, "every <$tag>");
        }
    }

    public function test_a_page_without_releases_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('no <h2 id="v…"> release headings');

        Migrator::split('<article class="prose"><p>Nothing.</p></article>');
    }

    public function test_a_heading_whose_id_and_version_disagree_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('v0-9-1 is headed 0.9.16');

        Migrator::split('<article class="prose"><h2 id="v0-9-1">0.9.16 &mdash; X</h2><p class="relmeta">2026&#8209;09&#8209;27</p></article>');
    }

    public function test_a_release_without_a_meta_line_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('0.9.16 has no <p class="relmeta">');

        Migrator::split('<article class="prose"><h2 id="v0-9-16">0.9.16 &mdash; X</h2><p>Body.</p></article>');
    }

    /**
     * @return list<ReleaseFile>
     */
    private function releases(): array
    {
        return Migrator::split((string) file_get_contents(self::CHANGELOG));
    }
}
