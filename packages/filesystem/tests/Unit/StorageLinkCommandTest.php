<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Tests\Unit;

use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Filesystem\Console\StorageLinkCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StorageLinkCommand::class)]
final class StorageLinkCommandTest extends TestCase
{
    private string $base;
    private string $target;
    private string $link;
    private FakeOutput $output;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/hydra-link-' . bin2hex(random_bytes(6));
        $this->target = $this->base . '/storage/public';
        $this->link = $this->base . '/public/storage';
        mkdir($this->base . '/public', 0o775, true);
        $this->output = new FakeOutput;
    }

    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->base);
    }

    public function test_it_links_the_web_root_to_the_public_disk(): void
    {
        $this->assertSame(ExitCode::Success, $this->link());

        $this->assertTrue(is_link($this->link));
        $this->assertSame(realpath($this->target), realpath($this->link));
        $this->output->assertSuccess();
    }

    public function test_the_link_is_relative_so_it_survives_a_different_mount_point(): void
    {
        $this->link();

        $this->assertSame('../storage/public', readlink($this->link));
    }

    public function test_the_public_disk_is_created_when_it_is_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->target);

        $this->link();

        $this->assertDirectoryExists($this->target);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->link();
        $this->output = new FakeOutput;

        $this->assertSame(ExitCode::Success, $this->link());
        $this->assertSame('../storage/public', readlink($this->link));
        $this->output->assertSaid('already');
    }

    public function test_a_real_directory_in_the_way_is_left_alone(): void
    {
        mkdir($this->link);
        touch($this->link . '/keep.txt');

        $this->assertSame(ExitCode::Failure, $this->link());

        $this->assertFalse(is_link($this->link));
        $this->assertFileExists($this->link . '/keep.txt');
        $this->output->assertError();
    }

    public function test_a_link_to_somewhere_else_is_left_alone(): void
    {
        mkdir($this->base . '/elsewhere');
        symlink($this->base . '/elsewhere', $this->link);

        $this->assertSame(ExitCode::Failure, $this->link());

        $this->assertSame($this->base . '/elsewhere', readlink($this->link));
        $this->output->assertError();
    }

    private function link(): ExitCode
    {
        return (new StorageLinkCommand($this->target, $this->link))->execute(new ArrayInput, $this->output);
    }
}
