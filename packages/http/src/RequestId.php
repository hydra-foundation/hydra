<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * The id of the request being handled, held where code with no request in hand
 * can read it — a logger, a reporter, a job being queued. Set once per request
 * by {@see RequestIdMiddleware}; null outside one, as in a console command.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    private ?string $value = null;

    public function get(): ?string
    {
        return $this->value;
    }

    public function set(string $value): void
    {
        $this->value = $value;
    }

    /** 128 random bits as 32 hex characters, the same shape as nginx's `$request_id`. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
