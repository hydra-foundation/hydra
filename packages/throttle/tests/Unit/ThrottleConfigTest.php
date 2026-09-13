<?php

declare(strict_types=1);

namespace Hydra\Throttle\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Throttle\ThrottleConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The application-wide budget as it comes out of the environment, and what an
 * unset environment gives you, which is the configuration most deployments run.
 */
final class ThrottleConfigTest extends TestCase
{
    /** The keys these tests clear so a .env can be read in isolation. */
    private const KEYS = ['RATE_LIMIT_ENABLED', 'RATE_LIMIT', 'RATE_LIMIT_WINDOW'];

    private string $dir;

    /** @var list<string> keys this test's .env exported to the process env */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-throttle-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $file = $this->dir . '/.env';

        if (file_exists($file)) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    public function test_it_is_on_by_default(): void
    {
        // A limiter that has to be remembered is one that is missing wherever
        // it was forgotten, so an application with no throttle settings at all
        // is still limited.
        $config = $this->fromEnv("APP_NAME=hydra\n");

        $this->assertTrue($config->enabled);
        $this->assertSame(120, $config->limit);
        $this->assertSame(60, $config->window);
    }

    public function test_it_maps_the_rate_limit_keys(): void
    {
        $config = $this->fromEnv("RATE_LIMIT_ENABLED=false\nRATE_LIMIT=30\nRATE_LIMIT_WINDOW=10\n");

        $this->assertFalse($config->enabled);
        $this->assertSame(30, $config->limit);
        $this->assertSame(10, $config->window);
    }

    public function test_the_policy_carries_the_configured_budget(): void
    {
        $policy = (new ThrottleConfig(limit: 30, window: 10))->policy();

        $this->assertSame(ThrottleConfig::NAME, $policy->name);
        $this->assertSame(30, $policy->limit);
        $this->assertSame(10, $policy->window);
    }

    public function test_a_budget_nobody_can_satisfy_fails_at_boot(): void
    {
        // Not at the first request that hit it: RATE_LIMIT=0 refuses every
        // caller, and that has to surface where it was configured.
        $this->expectException(InvalidArgumentException::class);

        (new ThrottleConfig(limit: 0))->policy();
    }

    private function fromEnv(string $contents): ThrottleConfig
    {
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        file_put_contents($this->dir . '/.env', $contents);

        foreach (explode("\n", $contents) as $line) {
            if (str_contains($line, '=')) {
                $this->written[] = trim(explode('=', $line, 2)[0]);
            }
        }

        return ThrottleConfig::fromEnvironment(new Environment($this->dir));
    }
}
