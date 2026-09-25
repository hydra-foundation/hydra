<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * Which other origins may call which paths, from the CORS_* settings. No
 * origins is the default and means CORS is off. There is no credentials
 * setting: tokens travel in a header, and cookies are not to cross origins.
 */
final readonly class CorsConfig
{
    /** @var list<string> */
    public array $allowedOrigins;

    /** @var list<string> */
    public array $allowedMethods;

    /**
     * @param list<string> $allowedOrigins exact `scheme://host[:port]`, or `*`
     * @param list<string> $paths path prefixes CORS applies under
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     * @param int $maxAge seconds a browser may cache a preflight answer
     */
    public function __construct(
        array $allowedOrigins = [],
        public array $paths = ['/api/'],
        array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        public array $allowedHeaders = ['Authorization', 'Content-Type', 'Accept'],
        public array $exposedHeaders = ['X-Request-Id'],
        public int $maxAge = 600,
    ) {
        $this->allowedOrigins = array_map(self::origin(...), $allowedOrigins);
        $this->allowedMethods = array_map(strtoupper(...), $allowedMethods);

        foreach ($paths as $path) {
            if (!str_starts_with($path, '/')) {
                throw new InvalidArgumentException("CORS path \"{$path}\" must start with a slash.");
            }
        }

        if ($maxAge < 0) {
            throw new InvalidArgumentException("CORS max age must not be negative; got {$maxAge}.");
        }
    }

    public static function fromEnvironment(Environment $env): self
    {
        $defaults = new self;

        return new self(
            allowedOrigins: $env->list('CORS_ALLOWED_ORIGINS'),
            paths: $env->list('CORS_PATHS', $defaults->paths),
            allowedMethods: $env->list('CORS_ALLOWED_METHODS', $defaults->allowedMethods),
            allowedHeaders: $env->list('CORS_ALLOWED_HEADERS', $defaults->allowedHeaders),
            exposedHeaders: $env->list('CORS_EXPOSED_HEADERS', $defaults->exposedHeaders),
            maxAge: $env->int('CORS_MAX_AGE', $defaults->maxAge),
        );
    }

    public function enabled(): bool
    {
        return $this->allowedOrigins !== [];
    }

    public function wildcard(): bool
    {
        return in_array('*', $this->allowedOrigins, true);
    }

    public function allows(string $origin): bool
    {
        return $this->wildcard() || in_array($origin, $this->allowedOrigins, true);
    }

    public function covers(string $path): bool
    {
        foreach ($this->paths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function origin(string $origin): string
    {
        if ($origin === '*') {
            return $origin;
        }

        $parts = parse_url($origin);

        if (
            $parts === false
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || array_diff_key($parts, ['scheme' => 1, 'host' => 1, 'port' => 1]) !== []
        ) {
            throw new InvalidArgumentException(
                "CORS origin \"{$origin}\" must be scheme://host[:port], with no path, not even a trailing slash.",
            );
        }

        return strtolower($origin);
    }
}
