<?php

declare(strict_types=1);

namespace Hydra\Core;

use Composer\InstalledVersions;

/**
 * Two versions, from the two places that know them. Hydra's is whatever
 * Composer installed. The application's belongs to its author, so it is read
 * from their own git history; a skeleton copied out by create-project has no
 * history of its own, and has no version yet.
 */
final class Versions
{
    private const PACKAGE = 'hydrakit/core';

    /** A full commit id, SHA-1 or SHA-256. */
    private const SHA = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D';

    private string|false|null $application = null;

    public function __construct(private readonly string $basePath) {}

    public function hydra(): string
    {
        // Versionless when the root replaces it, as the monorepo does.
        return InstalledVersions::getPrettyVersion(self::PACKAGE)
            ?? InstalledVersions::getRootPackage()['pretty_version'];
    }

    public function application(): ?string
    {
        $this->application ??= $this->describe();

        return $this->application === false ? null : $this->application;
    }

    /**
     * The commit the checkout is on, read from the repository's files rather
     * than by running git, so it is cheap enough for every request: what
     * HttpCache mixes into an ETag. Null with no repository or no commits.
     */
    public function commit(): ?string
    {
        $git = $this->gitDirectory();
        $head = $git === null ? '' : trim((string) @file_get_contents($git . '/HEAD'));

        if (preg_match(self::SHA, $head) === 1) {
            return $head;
        }

        if ($git === null || preg_match('#^ref: (refs/[A-Za-z0-9._/-]+)$#D', $head, $match) !== 1 || str_contains($match[1], '..')) {
            return null;
        }

        // A worktree keeps its HEAD and shares the main repository's refs.
        $common = trim((string) @file_get_contents($git . '/commondir'));
        $common = $common === '' ? $git : (str_starts_with($common, '/') ? $common : $git . '/' . $common);

        foreach ([$git, $common] as $directory) {
            $loose = trim((string) @file_get_contents($directory . '/' . $match[1]));

            if (preg_match(self::SHA, $loose) === 1) {
                return $loose;
            }
        }

        foreach (explode("\n", (string) @file_get_contents($common . '/packed-refs')) as $line) {
            $parts = explode(' ', trim($line), 2);

            if (count($parts) === 2 && $parts[1] === $match[1] && preg_match(self::SHA, $parts[0]) === 1) {
                return $parts[0];
            }
        }

        return null;
    }

    /** The repository directory: `.git`, or where a worktree's `.git` file points. */
    private function gitDirectory(): ?string
    {
        $dotGit = $this->basePath . '/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        if (is_file($dotGit) && preg_match('/^gitdir: (.+)$/m', (string) file_get_contents($dotGit), $match) === 1) {
            $path = trim($match[1]);

            return str_starts_with($path, '/') ? $path : $this->basePath . '/' . $path;
        }

        return null;
    }

    private function describe(): string|false
    {
        if (!is_dir($this->basePath . '/.git') && !is_file($this->basePath . '/.git')) {
            return false;
        }

        $process = @proc_open(
            ['git', '-C', $this->basePath, 'describe', '--tags', '--always'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            return false;
        }

        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $out !== '' ? $out : false;
    }
}
