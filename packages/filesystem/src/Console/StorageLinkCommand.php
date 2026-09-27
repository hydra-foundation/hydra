<?php

declare(strict_types=1);

namespace Hydra\Filesystem\Console;

use Hydra\Console\Attributes\AsCommand;
use Hydra\Console\Command;
use Hydra\Console\Contracts\InputInterface;
use Hydra\Console\Contracts\OutputInterface;
use Hydra\Console\ExitCode;

/**
 * Links the document root to the public disk, so the web server serves it.
 *
 * Safe to run on every container start: a link that is already right is left
 * alone. Anything else in the way (a real directory, a link somewhere else)
 * is left alone too and reported, because the one thing this must never do is
 * delete files it did not put there.
 *
 * The link is relative. The app directory is mounted at one path in a
 * container and another on the host, and an absolute link is right in only
 * one of them.
 */
#[AsCommand(
    name: 'storage:link',
    description: 'Link public/storage to the public disk so the web server serves it',
)]
final class StorageLinkCommand extends Command
{
    public function __construct(
        private readonly string $target,
        private readonly string $link,
    ) {}

    public function execute(InputInterface $input, OutputInterface $output): ExitCode
    {
        if (!is_dir($this->target) && !mkdir($this->target, 0o775, true) && !is_dir($this->target)) {
            $output->error("Could not create the public disk at {$this->target}.");

            return ExitCode::Failure;
        }

        $relative = $this->relative(dirname($this->link), $this->target);

        if (is_link($this->link)) {
            if (readlink($this->link) === $relative || realpath($this->link) === realpath($this->target)) {
                $output->note("{$this->link} is already linked.");

                return ExitCode::Success;
            }

            $output->error("{$this->link} already links to " . readlink($this->link) . '. Remove it and run this again.');

            return ExitCode::Failure;
        }

        if (file_exists($this->link)) {
            $output->error("{$this->link} already exists and is not a link. Move it aside and run this again.");

            return ExitCode::Failure;
        }

        if (!symlink($relative, $this->link)) {
            $output->error("Could not create the link at {$this->link}.");

            return ExitCode::Failure;
        }

        $output->success("Linked {$this->link} to {$relative}.");

        return ExitCode::Success;
    }

    /** $to, as a path relative to the directory $from. Both must exist. */
    private function relative(string $from, string $to): string
    {
        $from = explode('/', trim((string) realpath($from), '/'));
        $to = explode('/', trim((string) realpath($to), '/'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);
    }
}
