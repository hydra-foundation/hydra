<?php

declare(strict_types=1);

namespace Hydra\Scheduler\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Scheduler\SchedulerConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchedulerConfig::class)]
final class SchedulerConfigTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-scheduler-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        @unlink($this->dir . '/.env');
        rmdir($this->dir);
    }

    public function test_an_unset_environment_is_utc_and_the_given_lock_path(): void
    {
        $config = $this->config([]);

        $this->assertSame('UTC', $config->timezone->getName());
        $this->assertSame('/app/bootstrap/cache/scheduler', $config->lockPath);
    }

    public function test_the_applications_zone_is_the_fallback(): void
    {
        $this->assertSame('America/Edmonton', $this->config(['APP_TIMEZONE' => 'America/Edmonton'])->timezone->getName());
    }

    public function test_the_schedules_own_zone_wins(): void
    {
        $config = $this->config(['APP_TIMEZONE' => 'America/Edmonton', 'SCHEDULE_TIMEZONE' => 'Europe/London']);

        $this->assertSame('Europe/London', $config->timezone->getName());
    }

    public function test_the_lock_directory_can_be_moved(): void
    {
        $this->assertSame('/var/lock/app', $this->config(['SCHEDULE_LOCK_DIR' => '/var/lock/app'])->lockPath);
    }

    public function test_a_zone_php_does_not_know_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"Mars/Olympus"');

        $this->config(['SCHEDULE_TIMEZONE' => 'Mars/Olympus']);
    }

    /** @param array<string, string> $values */
    private function config(array $values): SchedulerConfig
    {
        $lines = [];

        foreach ($values as $key => $value) {
            $lines[] = "{$key}={$value}";
            $this->written[] = $key;
        }

        file_put_contents($this->dir . '/.env', implode("\n", $lines) . "\n");

        return SchedulerConfig::fromEnvironment(new Environment($this->dir), '/app/bootstrap/cache/scheduler');
    }
}
