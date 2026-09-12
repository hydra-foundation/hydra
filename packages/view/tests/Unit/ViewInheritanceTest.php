<?php

declare(strict_types=1);

namespace Hydra\View\Tests\Unit;

use Hydra\View\PhpView;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The layout side of Template: extends(), sections and their defaults, implicit
 * content capture, and the output buffering all of that depends on keeping
 * balanced.
 */
final class ViewInheritanceTest extends TestCase
{
    private string $dir;
    private PhpView $view;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-inherit-' . uniqid('', true);
        mkdir($this->dir);
        $this->view = new PhpView($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeTemplate(string $name, string $contents): void
    {
        $path = $this->dir . '/' . $name . '.php';
        $subdir = dirname($path);
        if (!is_dir($subdir)) {
            mkdir($subdir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function test_child_extends_layout_and_body_becomes_content(): void
    {
        $this->writeTemplate('layouts/base', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?><h1><?= $this->e($name) ?></h1>');

        $out = $this->view->render('home', ['name' => 'Hydra']);

        $this->assertSame('<body><h1>Hydra</h1></body>', $out);
    }

    public function test_named_section_is_captured_and_yielded(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title") ?></title><?= $this->section("content") ?>');
        $this->writeTemplate('home', implode('', [
            '<?php $this->extends("layouts/base") ?>',
            '<?php $this->start("title") ?>Home<?php $this->stop() ?>',
            'BODY',
        ]));

        $this->assertSame('<title>Home</title>BODY', $this->view->render('home'));
    }

    public function test_section_default_used_when_child_omits_it(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title", "Hydra") ?></title>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>x');

        $this->assertSame('<title>Hydra</title>', $this->view->render('home'));
    }

    public function test_named_section_content_is_excluded_from_implicit_content(): void
    {
        $this->writeTemplate('layouts/base', '[<?= $this->section("content") ?>]');
        $this->writeTemplate('home', implode('', [
            '<?php $this->extends("layouts/base") ?>',
            'A',
            '<?php $this->start("aside") ?>SIDEBAR<?php $this->stop() ?>',
            'B',
        ]));

        // The captured section must not leak into the implicit content body.
        $this->assertSame('[AB]', $this->view->render('home'));
    }

    public function test_multi_level_extends(): void
    {
        $this->writeTemplate('layouts/skeleton', 'S(<?= $this->section("content") ?>)');
        $this->writeTemplate('layouts/base', '<?php $this->extends("layouts/skeleton") ?>B(<?= $this->section("content") ?>)');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>H');

        // home -> base -> skeleton; each level's body becomes the next's content.
        $this->assertSame('S(B(H))', $this->view->render('home'));
    }

    public function test_sections_do_not_leak_between_renders(): void
    {
        $this->writeTemplate('layouts/base', '<title><?= $this->section("title", "default") ?></title>');
        $this->writeTemplate('with', '<?php $this->extends("layouts/base") ?><?php $this->start("title") ?>Set<?php $this->stop() ?>');
        $this->writeTemplate('without', '<?php $this->extends("layouts/base") ?>x');

        $this->assertSame('<title>Set</title>', $this->view->render('with'));
        // A fresh render must not see the previous render's "title" section.
        $this->assertSame('<title>default</title>', $this->view->render('without'));
    }

    public function test_escaping_still_applies_inside_layouts(): void
    {
        $this->writeTemplate('layouts/base', '<h1><?= $this->e($name) ?></h1><?= $this->section("content") ?>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?>body');

        $out = $this->view->render('home', ['name' => '<script>']);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_layout_false_returns_bare_body_ignoring_extends(): void
    {
        $this->writeTemplate('layouts/base', '<body><?= $this->section("content") ?></body>');
        $this->writeTemplate('home', '<?php $this->extends("layouts/base") ?><h1><?= $this->e($name) ?></h1>');

        // Same template, layout suppressed: the htmx-fragment path.
        $out = $this->view->render('home', ['name' => 'Hydra'], layout: false);

        $this->assertSame('<h1>Hydra</h1>', $out);
    }

    public function test_stop_without_start_throws(): void
    {
        $this->writeTemplate('bad', '<?php $this->stop() ?>');

        $this->expectException(RuntimeException::class);
        $this->view->render('bad');
    }

    public function test_start_without_stop_throws(): void
    {
        // An unclosed section would otherwise strand the body in a dangling
        // buffer; it must fail loudly, the mirror of stop()-without-start().
        $this->writeTemplate('bad', 'before<?php $this->start("x") ?>after');

        $this->expectException(RuntimeException::class);
        $this->view->render('bad');
    }

    public function test_start_without_stop_leaks_no_output_buffer(): void
    {
        $this->writeTemplate('bad', 'before<?php $this->start("x") ?>after');

        $level = ob_get_level();
        try {
            $this->view->render('bad');
            $this->fail('expected an unclosed start() to throw');
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame($level, ob_get_level(), 'no leaked output buffer');
    }

    public function test_shared_data_reaches_every_render_and_its_partials(): void
    {
        $view = new PhpView($this->dir, shared: ['theme' => 'paper']);

        $this->writeTemplate('shell', '<?= $this->e($theme) ?>|<?= $this->partial("inner") ?>');
        $this->writeTemplate('inner', '<?= $this->e($theme) ?>');

        $this->assertSame('paper|paper', $view->render('shell'));
    }

    public function test_a_renders_own_data_wins_over_the_shared(): void
    {
        $view = new PhpView($this->dir, shared: ['theme' => 'paper']);

        $this->writeTemplate('shell', '<?= $this->e($theme) ?>');

        $this->assertSame('graphite', $view->render('shell', ['theme' => 'graphite']));
    }
}
