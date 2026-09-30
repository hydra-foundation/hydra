<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Tests\Unit;

use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Environment reads a .env file and exports what it read to the real process
 * environment, where the real value beats the file. So each test writes its
 * settings to a file of its own, and every key it wrote is scrubbed after it,
 * except the REDIS_* keys a real Redis test needs, which are put back.
 */
trait WritesEnvironment
{
    /** The keys a Redis test reads to find a server, borrowed and returned. */
    private const BORROWED = ['REDIS_HOST', 'REDIS_PORT'];

    private string $envDir;

    /** @var list<string> */
    private array $written = [];

    /** @var array<string, string> */
    private array $borrowed = [];

    #[Before]
    protected function makeEnvironmentDirectory(): void
    {
        $this->envDir = sys_get_temp_dir() . '/hydra-broadcast-env-' . uniqid('', true);
        mkdir($this->envDir);

        foreach (self::BORROWED as $key) {
            $value = getenv($key);

            if ($value !== false) {
                $this->borrowed[$key] = $value;
            }
        }
    }

    #[After]
    protected function removeEnvironment(): void
    {
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        foreach ($this->borrowed as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        $this->borrowed = [];

        if (file_exists($this->envDir . '/.env')) {
            unlink($this->envDir . '/.env');
        }
        rmdir($this->envDir);
    }

    /**
     * Hide the real REDIS_HOST and REDIS_PORT until the test ends. A real
     * variable beats the .env file, and Environment reads it at the moment a
     * setting is asked for, so a test that needs its own address, such as one
     * with nothing behind it, would otherwise reach CI's server.
     */
    private function withoutRealRedisAddress(): void
    {
        foreach (self::BORROWED as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** @param array<string, string> $values */
    private function environment(array $values): Environment
    {
        $lines = '';
        foreach ($values as $key => $value) {
            $this->written[] = $key;
            $lines .= "{$key}={$value}\n";
        }

        file_put_contents($this->envDir . '/.env', $lines);

        return new Environment($this->envDir);
    }
}
