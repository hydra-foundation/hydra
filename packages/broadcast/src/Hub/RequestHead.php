<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

/**
 * A browser's request, read just far enough to decide: the method, the path,
 * and the token. Headers are not needed, so they are not parsed.
 */
final readonly class RequestHead
{
    /** @param array<array-key, mixed> $query */
    private function __construct(
        public string $method,
        public string $path,
        private array $query,
    ) {}

    /** Whether $buffer holds a whole request head. */
    public static function complete(string $buffer): bool
    {
        return str_contains($buffer, "\r\n\r\n");
    }

    /** The head in $buffer, or null when its request line is not HTTP. */
    public static function parse(string $buffer): ?self
    {
        $line = strstr($buffer, "\r\n", true);
        $parts = explode(' ', $line === false ? $buffer : $line);

        if (count($parts) !== 3 || !str_starts_with($parts[1], '/') || !str_starts_with($parts[2], 'HTTP/1.')) {
            return null;
        }

        [$method, $target] = $parts;
        $path = (string) parse_url($target, PHP_URL_PATH);
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        return new self($method, $path, $query);
    }

    public function isStream(): bool
    {
        return $this->method === 'GET' && $this->path === '/stream';
    }

    public function token(): ?string
    {
        $token = $this->query['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
