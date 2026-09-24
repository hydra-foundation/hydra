<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * bin/lock-behind.php, which stops release.sh tagging a skeleton whose lock
 * quietly kept a framework package at the release before.
 */
#[CoversNothing]
final class LockBehindTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../bin/lock-behind.php';

    private string $lock;

    protected function setUp(): void
    {
        $this->lock = tempnam(sys_get_temp_dir(), 'hydra-lock-');
    }

    protected function tearDown(): void
    {
        @unlink($this->lock);
    }

    public function test_a_lock_entirely_at_the_tag_says_nothing(): void
    {
        $this->assertSame([], $this->behind([
            'packages' => [['name' => 'hydrakit/core', 'version' => 'v0.9.6'], ['name' => 'php-di/php-di', 'version' => '7.0.0']],
            'packages-dev' => [['name' => 'hydrakit/view', 'version' => 'v0.9.6']],
        ]));
    }

    public function test_a_package_held_back_is_named_with_its_version(): void
    {
        $this->assertSame(['hydrakit/core v0.9.5', 'hydrakit/view v0.9.4'], $this->behind([
            'packages' => [['name' => 'hydrakit/core', 'version' => 'v0.9.5'], ['name' => 'hydrakit/http', 'version' => 'v0.9.6']],
            'packages-dev' => [['name' => 'hydrakit/view', 'version' => 'v0.9.4']],
        ]));
    }

    public function test_a_lock_without_dev_packages_is_read(): void
    {
        $this->assertSame(['hydrakit/core v0.9.5'], $this->behind([
            'packages' => [['name' => 'hydrakit/core', 'version' => 'v0.9.5']],
        ]));
    }

    public function test_an_unreadable_lock_fails_rather_than_passing(): void
    {
        file_put_contents($this->lock, 'not json');

        exec(sprintf('php %s %s v0.9.6 2>&1', escapeshellarg(self::SCRIPT), escapeshellarg($this->lock)), $output, $status);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('cannot read a lock', implode("\n", $output));
    }

    /**
     * @param array<string, mixed> $lock
     * @return list<string>
     */
    private function behind(array $lock): array
    {
        file_put_contents($this->lock, json_encode($lock));

        exec(sprintf('php %s %s v0.9.6', escapeshellarg(self::SCRIPT), escapeshellarg($this->lock)), $output, $status);

        $this->assertSame(0, $status);

        return array_values(array_filter($output, static fn (string $line): bool => $line !== ''));
    }
}
