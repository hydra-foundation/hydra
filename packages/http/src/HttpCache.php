<?php

declare(strict_types=1);

namespace Hydra\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;

/**
 * How a response may be kept, and what tells a later request it hasn't
 * changed. A controller builds one from the data its page is made of:
 *
 *     HttpCache::public(maxAge: 300)->etag($post->slug, $mtime)->lastModified($at)
 *
 * The ETag is weak and hashed from those parts plus the Release, never from
 * the body: every HTML page carries a fresh CSP nonce, so no two bodies match.
 *
 * Immutable: each method returns a new value.
 */
final readonly class HttpCache
{
    /** The HTTP date format, IMF-fixdate (RFC 9110 §5.6.7). */
    public const DATE = 'D, d M Y H:i:s \G\M\T';

    /** @param list<string|int|float|bool>|null $parts */
    private function __construct(
        private string $scope,
        private int $maxAge,
        private ?array $parts = null,
        private ?DateTimeImmutable $lastModified = null,
    ) {
        if ($maxAge < 0) {
            throw new InvalidArgumentException("A max age can't be negative; {$maxAge} was given.");
        }
    }

    /** Any cache may keep it: a page that is the same for everyone. */
    public static function public(int $maxAge = 0): self
    {
        return new self('public', $maxAge);
    }

    /** Only the reader's own browser may keep it. */
    public static function private(int $maxAge = 0): self
    {
        return new self('private', $maxAge);
    }

    /** Nothing may keep it: what a response that says nothing gets. */
    public static function noStore(): self
    {
        return new self('no-store', 0);
    }

    /** What the page is made of: a change to any part, or a deploy, is a new ETag. */
    public function etag(string|int|float|bool|Stringable ...$parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('An ETag is made of at least one part.');
        }

        $parts = array_values(array_map(static fn (mixed $part): string|int|float|bool => $part instanceof Stringable ? (string) $part : $part, $parts));

        return new self($this->scope, $this->maxAge, $parts, $this->lastModified);
    }

    public function lastModified(DateTimeInterface $at): self
    {
        $utc = DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));

        return new self($this->scope, $this->maxAge, $this->parts, $utc);
    }

    /**
     * The headers this policy sets.
     *
     * @return array<string, string>
     */
    public function headers(?Release $release = null): array
    {
        $headers = ['Cache-Control' => $this->cacheControl()];

        if ($this->parts !== null) {
            $hash = hash('xxh128', json_encode([$release?->id, ...$this->parts], JSON_THROW_ON_ERROR));
            $headers['ETag'] = 'W/"' . $hash . '"';
        }

        if ($this->lastModified !== null) {
            $headers['Last-Modified'] = $this->lastModified->format(self::DATE);
        }

        return $headers;
    }

    private function cacheControl(): string
    {
        return match (true) {
            $this->scope === 'no-store' => 'no-store',
            $this->maxAge === 0 => "{$this->scope}, no-cache",
            default => "{$this->scope}, max-age={$this->maxAge}",
        };
    }
}
