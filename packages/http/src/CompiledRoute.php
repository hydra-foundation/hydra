<?php

declare(strict_types=1);

namespace Hydra\Http;

/**
 * One registered route, with its path compiled to a matcher. Distinct from
 * the #[Route] attribute, which only declares one: this is what the Router
 * stores and matches a request against.
 */
final class CompiledRoute
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
        // single param, intentional since matching already happened per-segment.
        $params = [];
        foreach ($matches as $name => $value) {
            if (is_string($name)) {
                $params[$name] = rawurldecode($value);
            }
        }

        return $params;
    }

    /**
     * $path with each named parameter's segment replaced by its {name}, as
     * sent rather than decoded, so the rest of the path reads as it arrived.
     *
     * @param list<string> $names
     */
    public function mask(string $path, array $names): string
    {
        if (preg_match($this->pattern, $path, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $path;
        }

        $spans = [];
        foreach ($names as $name) {
            if (isset($matches[$name])) {
                $spans[$matches[$name][1]] = [strlen($matches[$name][0]), '{' . $name . '}'];
            }
        }

        krsort($spans);
        foreach ($spans as $offset => [$length, $placeholder]) {
            $path = substr_replace($path, $placeholder, $offset, $length);
        }

        return $path;
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
