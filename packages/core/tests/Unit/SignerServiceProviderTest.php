<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Security\Signer;
use Hydra\Core\Security\SignerServiceProvider;
use PHPUnit\Framework\TestCase;

final class SignerServiceProviderTest extends TestCase
{
    private const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private string $dir;

    /** @var list<string> keys the test .env exported to the process env */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-signer-provider-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment exports .env to the real process env and the real env
        // wins, so scrub what this test wrote or it leaks into later tests.
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    public function test_resolves_a_signer_when_app_key_is_set(): void
    {
        $container = $this->containerWithEnv("APP_KEY=" . self::KEY_HEX . "\n");

        (new SignerServiceProvider)->register($container);
        $signer = $container->get(Signer::class);

        $this->assertInstanceOf(Signer::class, $signer);
        // The resolved signer works with the configured key.
        $this->assertSame('ok', $signer->verify($signer->sign('ok')));
    }

    public function test_throws_at_resolve_when_app_key_is_missing(): void
    {
        $container = $this->containerWithEnv("APP_NAME=hydra\n");

        (new SignerServiceProvider)->register($container);

        // The requirement fails lazily, at first resolve, not at register().
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        $container->get(Signer::class);
    }

    public function test_throws_at_resolve_when_app_key_is_malformed(): void
    {
        $container = $this->containerWithEnv("APP_KEY=not-a-valid-hex-key\n");

        (new SignerServiceProvider)->register($container);

        $this->expectException(\InvalidArgumentException::class);

        $container->get(Signer::class);
    }

    private function containerWithEnv(string $envContents): ContainerInterface
    {
        file_put_contents($this->dir . '/.env', $envContents);
        foreach (explode("\n", $envContents) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            $this->written[] = trim(explode('=', $line, 2)[0]);
        }

        return $this->container(new Environment($this->dir));
    }

    /** A minimal container: resolves Environment, records/invokes singleton factories. */
    private function container(Environment $environment): ContainerInterface
    {
        return new class ($environment) implements ContainerInterface {
            /** @var array<string, callable> */
            private array $factories = [];
            /** @var array<string, mixed> */
            private array $resolved = [];

            public function __construct(private readonly Environment $environment) {}

            public function get(string $id): mixed
            {
                if ($id === Environment::class) {
                    return $this->environment;
                }
                return $this->resolved[$id] ??= ($this->factories[$id])();
            }

            public function has(string $id): bool
            {
                return $id === Environment::class || isset($this->factories[$id]);
            }

            public function singleton(string $abstract, callable|string $concrete): void
            {
                $this->factories[$abstract] = is_callable($concrete) ? $concrete : fn () => new $concrete();
            }

            public function instance(string $abstract, object $instance): void
            {
                $this->resolved[$abstract] = $instance;
            }

            public function bound(string $abstract): bool
            {
                return $this->has($abstract);
            }
        };
    }
}
