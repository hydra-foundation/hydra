<?php

declare(strict_types=1);

namespace Hydra\Image\Tests\Unit;

use GdImage;
use Hydra\Console\ArrayInput;
use Hydra\Console\ExitCode;
use Hydra\Console\Testing\FakeOutput;
use Hydra\Image\Console\VariantsCommand;
use Hydra\Image\GdImages;
use Hydra\Image\ImageOptions;
use Hydra\Image\Preset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every copy made ahead of the first reader, for a deploy; and with --prune,
 * the copies nothing would name any more taken away.
 */
#[CoversClass(VariantsCommand::class)]
#[CoversClass(GdImages::class)]
final class VariantsCommandTest extends TestCase
{
    private string $root;

    private string $disk;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/hydra-variants-' . bin2hex(random_bytes(6));
        $this->root = $base . '/public';
        $this->disk = $base . '/storage';
        mkdir($this->root . '/images/posts', 0o777, true);
        mkdir($this->root . '/icons', 0o777, true);
        mkdir($this->disk . '/avatars', 0o777, true);
        symlink($this->disk, $this->root . '/storage');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    public function test_it_makes_every_copy_of_every_picture_it_walks(): void
    {
        $this->draw($this->root . '/images/posts/garden.jpg', 1000, 500);
        $this->draw($this->root . '/images/logo.png', 300, 300);
        $this->draw($this->disk . '/avatars/9c1e.png', 600, 600);
        $this->draw($this->root . '/icons/favicon.png', 64, 64);
        file_put_contents($this->root . '/images/notes.txt', 'not a picture');

        [$code, $output] = $this->command();

        $this->assertSame(ExitCode::Success, $code);
        $this->assertSame([
            'variants/content/' => 5,    // garden 480 + 1000, logo 300, avatar 480 + 600
            'variants/thumb/' => 3,
        ], $this->counted());
        $this->assertStringContainsString('3 pictures', implode("\n", $output->lines()));
    }

    public function test_a_second_run_makes_nothing(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        $this->command();
        $made = $this->files();
        foreach ($made as $file) {
            touch($file, 1_000_000_000);
        }
        clearstatcache();

        $this->command();

        foreach ($made as $file) {
            $this->assertSame(1_000_000_000, filemtime($file));
        }
    }

    public function test_a_picture_that_wont_decode_is_reported_and_the_rest_are_made(): void
    {
        file_put_contents($this->root . '/images/broken.jpg', 'nope');
        $this->draw($this->root . '/images/fine.jpg', 500, 500);

        [$code, $output] = $this->command();

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertStringContainsString('/images/broken.jpg', implode("\n", $output->lines()));
        $this->assertSame(['variants/content/' => 2, 'variants/thumb/' => 1], $this->counted());
    }

    public function test_prune_takes_away_only_copies_nothing_names(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        $this->draw($this->root . '/images/gone.jpg', 1000, 500);
        $this->command();
        unlink($this->root . '/images/gone.jpg');
        mkdir($this->disk . '/variants/retired', 0o777, true);
        file_put_contents($this->disk . '/variants/retired/x-100.webp', 'old preset');
        $kept = $this->files();

        [$code, $output] = $this->command(prune: true);

        $this->assertSame(ExitCode::Success, $code);
        $this->assertSame(['variants/content/' => 2, 'variants/thumb/' => 1], $this->counted());
        $this->assertCount(count($kept) - 4, $this->files());
        $this->assertStringContainsString('Removed 4', implode("\n", $output->lines()));
        $this->assertFileExists($this->disk . '/avatars', 'nothing outside variants/ is touched');
    }

    public function test_prune_keeps_everything_when_a_source_fails(): void
    {
        $this->draw($this->root . '/images/a.jpg', 1000, 500);
        $this->command();
        file_put_contents($this->root . '/images/a.jpg', 'broken now');
        $before = $this->files();

        [$code] = $this->command(prune: true);

        $this->assertSame(ExitCode::Failure, $code);
        $this->assertSame($before, $this->files(), 'a picture that failed may still be named by a page');
    }

    public function test_prune_is_a_flag_it_declares(): void
    {
        $options = new ImageOptions([]);
        $command = new VariantsCommand(new GdImages($options, $this->root, $this->disk), $options, $this->root, $this->disk);

        $this->assertSame(['prune'], array_map(static fn ($option): string => $option->name, $command->options()));
        $this->assertFalse($command->options()[0]->takesValue);
    }

    public function test_one_picture_is_one_picture(): void
    {
        $this->draw($this->root . '/images/PHOTO.JPG', 300, 300);
        mkdir($this->disk . '/variantsheets');

        [, $output] = $this->command();

        $this->assertStringContainsString('1 picture,', implode("\n", $output->lines()));
        $this->assertSame(['variants/content/' => 1, 'variants/thumb/' => 1], $this->counted());
    }

    public function test_a_disk_folder_that_only_begins_like_the_copies_is_walked(): void
    {
        mkdir($this->disk . '/variantsheets');
        $this->draw($this->disk . '/variantsheets/a.png', 300, 300);
        exec('rm -rf ' . escapeshellarg($this->root . '/images'));

        [, $output] = $this->command();

        $this->assertStringContainsString('1 picture,', implode("\n", $output->lines()));
    }

    public function test_a_failed_prune_says_why_nothing_went(): void
    {
        file_put_contents($this->root . '/images/broken.jpg', 'nope');

        [, $output] = $this->command(prune: true);

        $this->assertStringContainsString('Nothing pruned', implode("\n", $output->lines()));
    }

    public function test_nothing_to_walk_is_fine(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root . '/images'));

        [$code, $output] = $this->command();

        $this->assertSame(ExitCode::Success, $code);
        $this->assertStringContainsString('0 pictures', implode("\n", $output->lines()));
    }

    /** @return array{ExitCode, FakeOutput} */
    private function command(bool $prune = false): array
    {
        $options = new ImageOptions(
            ['content' => Preset::widths(480, 1000), 'thumb' => Preset::widths(160)->crop(1, 1)],
            directories: ['/images'],
        );
        $images = new GdImages($options, $this->root, $this->disk);
        $output = new FakeOutput;
        $code = (new VariantsCommand($images, $options, $this->root, $this->disk))
            ->execute(ArrayInput::withFlags($prune ? ['prune'] : []), $output);

        return [$code, $output];
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];

        if (is_dir($this->disk . '/variants')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->disk . '/variants', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = (string) $file;
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, int> copies per preset directory, thumb sizes deduplicated */
    private function counted(): array
    {
        $counts = [];

        foreach ($this->files() as $file) {
            $directory = 'variants/' . basename(dirname($file)) . '/';
            $counts[$directory] = ($counts[$directory] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function draw(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertInstanceOf(GdImage::class, $image);
        str_ends_with($path, '.png') ? imagepng($image, $path) : imagejpeg($image, $path);
    }
}
