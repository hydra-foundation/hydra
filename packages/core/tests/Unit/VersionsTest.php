<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Versions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Versions::class)]
final class VersionsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-versions-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function test_hydra_is_the_version_composer_installed(): void
    {
        $this->assertNotEmpty((new Versions($this->dir))->hydra());
    }

    public function test_an_application_without_a_repository_has_no_version(): void
    {
        $this->assertNull((new Versions($this->dir))->application());
    }

    public function test_a_repository_with_no_commits_has_no_version(): void
    {
        $this->git('init', '-q');

        $this->assertNull((new Versions($this->dir))->application());
    }

    public function test_an_untagged_repository_is_named_by_its_commit(): void
    {
        $this->commit();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{7,}$/', (string) (new Versions($this->dir))->application());
    }

    public function test_a_tagged_commit_is_named_by_its_tag(): void
    {
        $this->commit();
        $this->git('tag', 'v1.2.0');

        $this->assertSame('v1.2.0', (new Versions($this->dir))->application());
    }

    public function test_a_commit_past_a_tag_says_how_far_past(): void
    {
        $this->commit();
        $this->git('tag', 'v1.2.0');
        $this->commit();

        $this->assertMatchesRegularExpression('/^v1\.2\.0-1-g[0-9a-f]{7,}$/', (string) (new Versions($this->dir))->application());
    }

    private function commit(): void
    {
        if (!is_dir($this->dir . '/.git')) {
            $this->git('init', '-q');
        }

        $this->git('commit', '-q', '--allow-empty', '-m', 'commit');
    }

    private function git(string ...$args): void
    {
        $command = implode(' ', array_map('escapeshellarg', [
            'git', '-C', $this->dir,
            '-c', 'user.name=Test', '-c', 'user.email=test@example.com',
            '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false',
            ...$args,
        ]));

        exec($command . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
