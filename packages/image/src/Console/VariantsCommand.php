<?php

declare(strict_types=1);

namespace Hydra\Image\Console;

use FilesystemIterator;
use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;
use Hydra\Console\Option;
use Hydra\Image\GdImages;
use Hydra\Image\ImageOptions;
use Hydra\Image\ImageRefused;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every copy of every picture made now rather than on a reader's first view:
 * for a deploy. It walks the public disk (outside `variants/`) and the
 * ImageOptions directories under the document root, for every preset.
 *
 * With --prune it then removes the files under `variants/` that no picture
 * and preset would name any more: copies of edited, deleted or re-preset
 * pictures. A picture it couldn't read might still be named by a page, so a
 * run with any failure prunes nothing.
 */
#[AsCommand(
    name: 'image:variants',
    description: 'Make every picture\'s copies now; --prune removes copies nothing names',
)]
final class VariantsCommand extends Command
{
    private const PICTURES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        private readonly GdImages $images,
        private readonly ImageOptions $options,
        private readonly string $documentRoot,
        private readonly string $diskRoot,
    ) {}

    public function options(): array
    {
        return [Option::flag('prune', null, 'Remove copies no picture and preset would name')];
    }

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        $sources = $this->sources();
        $keep = [];
        $failed = [];

        foreach ($sources as $source) {
            foreach (array_keys($this->options->presets) as $preset) {
                try {
                    $this->images->variants($source, $preset);
                    $keep = [...$keep, ...array_fill_keys($this->images->files($source, $preset), true)];
                } catch (ImageRefused $e) {
                    $failed[$source] = $e->getMessage();
                }
            }
        }

        $output->success(sprintf('%d %s, every preset made.', count($sources), count($sources) === 1 ? 'picture' : 'pictures'));

        foreach ($failed as $source => $reason) {
            $output->error("{$source}: {$reason}");
        }

        if ($input->flag('prune')) {
            if ($failed !== []) {
                $output->note('Nothing pruned: a picture that failed may still be named by a page.');
            } else {
                $output->success(sprintf('Removed %d copies nothing names.', $this->prune($keep)));
            }
        }

        return $failed === [] ? ExitCode::Success : ExitCode::Failure;
    }

    /** @return list<string> every picture, as a view would name it */
    private function sources(): array
    {
        $sources = [];

        foreach ($this->pictures($this->diskRoot) as $relative) {
            if (!str_starts_with($relative, GdImages::DIRECTORY . '/')) {
                $sources[] = $relative;
            }
        }

        foreach ($this->options->directories as $directory) {
            foreach ($this->pictures($this->documentRoot . $directory) as $relative) {
                $sources[] = rtrim($directory, '/') . '/' . $relative;
            }
        }

        sort($sources);

        return $sources;
    }

    /** @return list<string> pictures under $directory, relative to it; links not followed */
    private function pictures(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), self::PICTURES, true)) {
                $found[] = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);
            }
        }

        return $found;
    }

    /** @param array<string, true> $keep */
    private function prune(array $keep): int
    {
        $removed = 0;

        foreach ($this->pictures($this->diskRoot . '/' . GdImages::DIRECTORY) as $relative) {
            $name = GdImages::DIRECTORY . '/' . $relative;

            if (!isset($keep[$name]) && @unlink($this->diskRoot . '/' . $name)) {
                $removed++;
            }
        }

        return $removed;
    }
}
