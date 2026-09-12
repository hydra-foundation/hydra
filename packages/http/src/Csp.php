<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * A Content-Security-Policy as an ordered map of directive => source list,
 * compiled to one header value. Immutable: every builder hands back a new policy.
 */
final readonly class Csp
{
    /**
     * Stands in for the nonce of the request being served. Put it in any
     * directive's source list; compile() swaps it for that request's token.
     */
    public const NONCE = '%nonce%';

    /** @param array<string, list<string>> $directives */
    public function __construct(private array $directives = []) {}

    /**
     * Nothing but the origin's own content: no plugins, no <base> an injection
     * could repoint, no posting elsewhere, and inline script only where the
     * server stamped the request's nonce. What a particular app also needs (a
     * font host, data: images) is the app's to add, not the framework's to
     * guess.
     */
    public static function default(): self
    {
        return new self([
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'script-src' => ["'self'", self::NONCE],
        ]);
    }

    /**
     * Set a directive, replacing whatever it allowed before. Passing no sources
     * declares a valueless directive such as upgrade-insecure-requests.
     */
    public function with(string $directive, string ...$sources): self
    {
        return new self([...$this->directives, $directive => array_values(array_unique($sources))]);
    }

    /** Adds sources to a directive, keeping the ones it already allows. */
    public function allow(string $directive, string ...$sources): self
    {
        return $this->with($directive, ...[...$this->directives[$directive] ?? [], ...$sources]);
    }

    public function without(string $directive): self
    {
        $directives = $this->directives;
        unset($directives[$directive]);

        return new self($directives);
    }

    public function compile(string $nonce): string
    {
        $parts = [];

        foreach ($this->directives as $directive => $sources) {
            $sources = array_map(
                fn (string $source): string => $source === self::NONCE ? "'nonce-{$nonce}'" : $source,
                $sources,
            );

            $parts[] = rtrim($directive . ' ' . implode(' ', $sources));
        }

        return implode('; ', $parts);
    }
}
