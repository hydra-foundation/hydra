<?php

declare(strict_types=1);

namespace Hydra\Session\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Session\SessionConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SessionConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-sessionconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // Environment writes to putenv()/$_ENV, so values leak across tests via
        // getenv() unless we scrub the keys this suite touches.
        foreach (['SESSION_NAME', 'SESSION_LIFETIME', 'SESSION_PATH', 'SESSION_DOMAIN', 'SESSION_SECURE', 'SESSION_HTTP_ONLY', 'SESSION_SAME_SITE'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }

        $envFile = $this->dir . '/.env';
        if (file_exists($envFile)) {
            unlink($envFile);
        }
        rmdir($this->dir);
    }

    private function fromEnv(string $contents): SessionConfig
    {
        file_put_contents($this->dir . '/.env', $contents);
        return SessionConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_exposes_readonly_fields_from_constructor(): void
    {
        $config = new SessionConfig(
            name: 'sess',
            lifetime: 3600,
            path: '/app',
            domain: 'example.test',
            secure: true,
            httpOnly: false,
            sameSite: 'Strict',
        );

        $this->assertSame('sess', $config->name);
        $this->assertSame(3600, $config->lifetime);
        $this->assertSame('/app', $config->path);
        $this->assertSame('example.test', $config->domain);
        $this->assertTrue($config->secure);
        $this->assertFalse($config->httpOnly);
        $this->assertSame('Strict', $config->sameSite);
    }

    public function test_maps_environment_keys(): void
    {
        $config = $this->fromEnv(
            "SESSION_NAME=myapp_sess\n" .
            "SESSION_LIFETIME=7200\n" .
            "SESSION_PATH=/sub\n" .
            "SESSION_DOMAIN=hydra.test\n" .
            "SESSION_SECURE=true\n" .
            "SESSION_HTTP_ONLY=false\n" .
            "SESSION_SAME_SITE=Strict\n"
        );

        $this->assertSame('myapp_sess', $config->name);
        $this->assertSame(7200, $config->lifetime);
        $this->assertSame('/sub', $config->path);
        $this->assertSame('hydra.test', $config->domain);
        $this->assertTrue($config->secure);
        $this->assertFalse($config->httpOnly);
        $this->assertSame('Strict', $config->sameSite);
    }

    public function test_applies_safe_defaults_when_keys_absent(): void
    {
        $config = $this->fromEnv("APP_NAME=x\n");

        $this->assertSame('hydra_session', $config->name);
        $this->assertSame(0, $config->lifetime);
        $this->assertSame('/', $config->path);
        $this->assertSame('', $config->domain);
        $this->assertFalse($config->secure); // off so local http dev works
        $this->assertTrue($config->httpOnly); // on by default
        $this->assertSame('Lax', $config->sameSite);
    }

    public function test_rejects_an_illegal_same_site(): void
    {
        // A typo on a security setting must not slip through to PHP unnoticed.
        $this->expectException(InvalidArgumentException::class);
        new SessionConfig(sameSite: 'lax'); // wrong case is still illegal
    }

    public function test_rejects_same_site_none_without_secure(): void
    {
        // Browsers drop an insecure None cookie, so the pairing is invalid.
        $this->expectException(InvalidArgumentException::class);
        new SessionConfig(secure: false, sameSite: 'None');
    }

    public function test_rejects_a_negative_lifetime(): void
    {
        // A negative lifetime is an already-expired cookie: the session would
        // silently never persist.
        $this->expectException(InvalidArgumentException::class);
        new SessionConfig(lifetime: -1);
    }

    public function test_allows_same_site_none_when_secure(): void
    {
        $config = new SessionConfig(secure: true, sameSite: 'None');

        $this->assertSame('None', $config->sameSite);
    }

    public function test_cookie_params_shape_matches_php_session_api(): void
    {
        $config = new SessionConfig(lifetime: 120, path: '/p', domain: 'd', secure: true, httpOnly: true, sameSite: 'Lax');

        $this->assertSame([
            'lifetime' => 120,
            'path' => '/p',
            'domain' => 'd',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $config->cookieParams());
    }
}
