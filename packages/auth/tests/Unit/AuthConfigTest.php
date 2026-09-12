<?php

declare(strict_types=1);

namespace Hydra\Auth\Tests\Unit;

use Hydra\Auth\AuthConfig;
use Hydra\Core\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * AuthConfig's cost setting: how it reads from the environment, and that a cost
 * outside bcrypt's range is refused at construction rather than surfacing later
 * as a hash that never verifies.
 */
final class AuthConfigTest extends TestCase
{
    private string $dir;

    /** @var list<string> keys this test's .env may have exported to the process env */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-authconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV, so values leak across tests via
        // getenv() unless we scrub the keys this suite touches. Scrub what was
        // actually written: an allowlist silently rots as cases are added.
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): AuthConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        $this->recordKeys($contents);

        return AuthConfig::fromEnvironment(new Environment($this->dir));
    }

    private function recordKeys(string $contents): void
    {
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            $this->written[] = trim(explode('=', $line, 2)[0]);
        }
    }

    public function test_default_cost(): void
    {
        $this->assertSame(12, (new AuthConfig)->hashCost);
    }

    public function test_maps_environment_key(): void
    {
        $this->assertSame(13, $this->fromEnv("AUTH_HASH_COST=13\n")->hashCost);
    }

    public function test_applies_default_when_key_absent(): void
    {
        $this->assertSame(12, $this->fromEnv("APP_NAME=x\n")->hashCost);
    }

    public function test_rejects_a_cost_below_the_bcrypt_minimum(): void
    {
        // 3 would make password_hash warn and return false, a non-hash. Reject
        // it at construction rather than letting it surface as a failed verify.
        $this->expectException(InvalidArgumentException::class);
        new AuthConfig(hashCost: 3);
    }

    public function test_rejects_a_cost_above_the_bcrypt_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthConfig(hashCost: 32);
    }

    public function test_accepts_the_range_bounds(): void
    {
        $this->assertSame(4, (new AuthConfig(hashCost: 4))->hashCost);
        $this->assertSame(31, (new AuthConfig(hashCost: 31))->hashCost);
    }
}
