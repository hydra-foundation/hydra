<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Http\CorsConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CorsConfig::class)]
final class CorsConfigTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hydra-corsconfig-' . uniqid('', true);
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->written = [];

        if (file_exists($this->dir . '/.env')) {
            unlink($this->dir . '/.env');
        }
        rmdir($this->dir);
    }

    /** @param array<string, string> $env */
    private function fromEnv(array $env): CorsConfig
    {
        $lines = '';
        foreach ($env as $key => $value) {
            $lines .= "{$key}={$value}\n";
            $this->written[] = $key;
        }
        file_put_contents($this->dir . '/.env', $lines);

        return CorsConfig::fromEnvironment(new Environment($this->dir));
    }

    public function test_an_unset_environment_is_disabled_with_the_documented_defaults(): void
    {
        $config = $this->fromEnv([]);

        $this->assertFalse($config->enabled());
        $this->assertSame(['/api/'], $config->paths);
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $config->allowedMethods);
        $this->assertSame(['Authorization', 'Content-Type', 'Accept'], $config->allowedHeaders);
        $this->assertSame(['X-Request-Id'], $config->exposedHeaders);
        $this->assertSame(600, $config->maxAge);
    }

    public function test_every_key_is_read(): void
    {
        $config = $this->fromEnv([
            'CORS_ALLOWED_ORIGINS' => 'https://a.test, http://localhost:5173',
            'CORS_PATHS' => '/api/,/hooks/',
            'CORS_ALLOWED_METHODS' => 'get,post',
            'CORS_ALLOWED_HEADERS' => 'Authorization',
            'CORS_EXPOSED_HEADERS' => 'X-Request-Id,Link',
            'CORS_MAX_AGE' => '60',
        ]);

        $this->assertTrue($config->enabled());
        $this->assertSame(['https://a.test', 'http://localhost:5173'], $config->allowedOrigins);
        $this->assertSame(['/api/', '/hooks/'], $config->paths);
        $this->assertSame(['GET', 'POST'], $config->allowedMethods);
        $this->assertSame(['Authorization'], $config->allowedHeaders);
        $this->assertSame(['X-Request-Id', 'Link'], $config->exposedHeaders);
        $this->assertSame(60, $config->maxAge);
    }

    public function test_an_origin_is_allowed_only_by_exact_match(): void
    {
        $config = new CorsConfig(allowedOrigins: ['https://a.test']);

        $this->assertTrue($config->allows('https://a.test'));
        $this->assertFalse($config->allows('https://a.test:8443'));
        $this->assertFalse($config->allows('http://a.test'));
        $this->assertFalse($config->allows('https://evil-a.test'));
        $this->assertFalse($config->allows('https://a.test.evil'));
        $this->assertFalse($config->allows('null'));
    }

    public function test_configured_origins_are_compared_in_lowercase(): void
    {
        $this->assertTrue((new CorsConfig(allowedOrigins: ['HTTPS://A.Test']))->allows('https://a.test'));
    }

    public function test_a_wildcard_allows_any_origin(): void
    {
        $config = new CorsConfig(allowedOrigins: ['*']);

        $this->assertTrue($config->wildcard());
        $this->assertTrue($config->allows('https://anything.test'));
    }

    public function test_a_path_is_covered_by_prefix(): void
    {
        $config = new CorsConfig(allowedOrigins: ['https://a.test']);

        $this->assertTrue($config->covers('/api/v1/me'));
        $this->assertFalse($config->covers('/api'));
        $this->assertFalse($config->covers('/admin'));
    }

    /** @return iterable<string, array{string}> */
    public static function badOrigins(): iterable
    {
        yield 'trailing slash' => ['https://a.test/'];
        yield 'a path' => ['https://a.test/app'];
        yield 'not http' => ['ftp://a.test'];
        yield 'no scheme' => ['a.test'];
        yield 'a query' => ['https://a.test?x=1'];
        yield 'credentials' => ['https://u:p@a.test'];
    }

    #[DataProvider('badOrigins')]
    public function test_a_malformed_origin_is_refused(string $origin): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($origin);

        new CorsConfig(allowedOrigins: [$origin]);
    }

    public function test_a_path_must_be_absolute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsConfig(paths: ['api/']);
    }

    public function test_a_negative_max_age_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsConfig(maxAge: -1);
    }
}
