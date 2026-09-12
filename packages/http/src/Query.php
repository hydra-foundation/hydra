<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader for a request's query string
 */
final class Query extends FieldReader
{
    /** @param array<string, mixed> $params */
    private function __construct(array $params)
    {
        parent::__construct($params);
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self($request->getQueryParams());
    }

    /**
     * The query string of a URL somebody else is on, read the same way as the
     * current request's own. For a handler that has to answer in terms of the
     * page the browser came from rather than the URL it posted to.
     */
    public static function fromUrl(string $url): self
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return new self($params);
    }
}
