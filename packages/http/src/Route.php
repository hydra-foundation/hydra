<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * A single registered route: an HTTP method, a path, and a handler target
 */
final class Route
{
    /** Compiled regex with named groups, anchored to the full path. */
    private readonly string $pattern;

    /** @param list<class-string> $middleware PSR-15 middleware for this route, outermost first */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $target,
        public readonly array $middleware = [],
    ) {
        $this->pattern = $this->compile($path);
    }

    /**
     * Match a (already-normalized) request path against this route.
     *
     * @return array<string, string>|null extracted params on match, null otherwise.
     *         Static routes return an empty array.
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        // Keep only named captures, url-decoded (e.g. "john%20doe" => "john doe").
        // Note: a percent-encoded slash (%2F) decodes to a literal "/" inside a
        // single param — intentional, since matching already happened per-segment.
        $params = [];
        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $params[$name] = rawurldecode($value);
            }
        }

        return $params;
    }

    /**
     * Turn "/users/{id}" into "#^/users/(?P<id>[^/]+)$#":
     * literal segments are regex-escaped, {name} placeholders become named
     * captures that stop at a slash (one path segment each).
     */
    private function compile(string $path): string
    {
        $segments = preg_split(
            '/(\{\w+\})/',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $regex = '';
        $seen = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $m) === 1) {
                if (isset($seen[$m[1]])) {
                    throw new \InvalidArgumentException(
                        "Route \"{$path}\" declares parameter {{$m[1]}} more than once."
                    );
                }
                $seen[$m[1]] = true;
                $regex .= '(?P<' . $m[1] . '>[^/]+)';
            } else {
                $regex .= preg_quote($segment, '#');
            }
        }

        return '#^' . $regex . '$#';
    }
}
