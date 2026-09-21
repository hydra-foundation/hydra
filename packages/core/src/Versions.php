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

    private string|false|null $application = null;

    public function __construct(private readonly string $basePath) {}

    public function hydra(): ?string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        // Versionless when the root replaces it, as the monorepo does.
        return InstalledVersions::getPrettyVersion(self::PACKAGE)
            ?? InstalledVersions::getRootPackage()['pretty_version'];
    }

    public function application(): ?string
    {
        $this->application ??= $this->describe();

        return $this->application === false ? null : $this->application;
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
