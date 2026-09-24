<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use Hydra\Core\Contracts\HealthCheckInterface;
use RuntimeException;

/**
 * A health check whose answer the test decides, and can change between probes.
 */
final class FakeHealthCheck implements HealthCheckInterface
{
    private int $runs = 0;

    private function __construct(
        private readonly string $name,
        private ?string $failure,
    ) {}

    public static function passing(string $name): self
    {
        return new self($name, null);
    }

    public static function failing(string $name, string $reason = 'not answering'): self
    {
        return new self($name, $reason);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        $this->runs++;

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }
    }

    public function recover(): void
    {
        $this->failure = null;
    }

    public function fail(string $reason = 'not answering'): void
    {
        $this->failure = $reason;
    }

    public function runs(): int
    {
        return $this->runs;
    }
}
