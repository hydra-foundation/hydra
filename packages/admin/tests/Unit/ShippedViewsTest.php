<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Renderer;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ShippedViewsTest extends TestCase
{
    /**
     * The admin renders by name, and a name with no file behind it fails at the
     * moment somebody opens the screen. Every name the package writes down —
     * in a controller or in one of its own templates — has to be one it ships,
     * or the application is quietly expected to supply it.
     */
    public function test_every_template_the_admin_names_is_one_it_ships(): void
    {
        $names = $this->templateNames();

        $this->assertContains('admin/screen', $names, 'the template scan found nothing it should have');

        foreach ($names as $name) {
            $this->assertFileExists(
                AdminServiceProvider::views() . '/' . $name . '.php',
                "the admin renders \"{$name}\" but does not ship it",
            );
        }
    }

    /**
     * A shipped template reaching for one the application happens to have is
     * the same hole seen from the other side: it renders here and 500s in the
     * next application. Every partial a shipped template pulls in is named
     * outright, so scan for the call rather than for the naming convention.
     */
    public function test_no_shipped_template_reaches_for_one_the_application_owns(): void
    {
        $pulled = $this->matchesInShippedViews("~partial\('([^']+)'~");

        $this->assertNotEmpty($pulled);

        foreach ($pulled as $name) {
            $this->assertFileExists(
                AdminServiceProvider::views() . '/' . $name . '.php',
                "a shipped template renders \"{$name}\", which the package does not ship",
            );
        }
    }

    public function test_the_shipped_views_are_where_the_provider_says_they_are(): void
    {
        $this->assertDirectoryExists(AdminServiceProvider::views());
    }

    /**
     * The swap depths are a contract between markup and {@see Renderer}, held
     * on both sides by a bare string: the templates aim at an id, the renderer
     * reads the id back off the request. Renaming one and not the others is a
     * silent wrong-depth render, so nothing here may aim anywhere else.
     */
    public function test_every_swap_aims_at_a_depth_the_renderer_knows(): void
    {
        $targets = $this->matchesInShippedViews('~hx-target="\#([a-z-]+)"~');

        $this->assertNotEmpty($targets);
        $this->assertSame(
            [],
            array_values(array_diff($targets, [Renderer::FRAME, Renderer::BODY])),
            'a template swaps against an element the renderer does not answer for',
        );
    }

    public function test_both_swap_depths_are_declared_exactly_once(): void
    {
        $declared = $this->matchesInShippedViews('#\bid="([a-z-]+)"#', unique: false);

        foreach ([Renderer::FRAME, Renderer::BODY] as $depth) {
            $this->assertSame(
                1,
                count(array_keys($declared, $depth, true)),
                "\"{$depth}\" must be declared by exactly one shipped template",
            );
        }
    }

    /** @return list<string> */
    private function matchesInShippedViews(string $pattern, bool $unique = true): array
    {
        $found = [];

        foreach ($this->filesUnder(AdminServiceProvider::views()) as $file) {
            preg_match_all($pattern, (string) file_get_contents($file), $matches);
            $found = [...$found, ...$matches[1]];
        }

        return $unique ? array_values(array_unique($found)) : $found;
    }

    /**
     * Every "admin/..." template name written in the package's source or its
     * own templates. The leading segment is what separates a template name from
     * a request path, which is spelled "/admin".
     *
     * @return list<string>
     */
    private function templateNames(): array
    {
        $names = [];

        foreach ([$this->package() . '/src', AdminServiceProvider::views()] as $root) {
            foreach ($this->filesUnder($root) as $file) {
                preg_match_all(
                    "#'(admin/[a-z0-9/_-]+)'#",
                    (string) file_get_contents($file),
                    $matches,
                );
                $names = [...$names, ...$matches[1]];
            }
        }

        return array_values(array_unique($names));
    }

    /** @return list<string> */
    private function filesUnder(string $root): array
    {
        $files = [];
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        /** @var SplFileInfo $file */
        foreach ($tree as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function package(): string
    {
        return dirname(__DIR__, 2);
    }
}
