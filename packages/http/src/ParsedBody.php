<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed reader over a request's parsed body, the form-field counterpart to
 * {@see Query}.
 */
final class ParsedBody extends FieldReader
{
    public function __construct(ServerRequestInterface $request)
    {
        parent::__construct((array) $request->getParsedBody());
    }

    public static function fromRequest(ServerRequestInterface $request): self
    {
        return new self($request);
    }
}
