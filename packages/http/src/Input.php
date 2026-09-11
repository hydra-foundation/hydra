<?php

declare(strict_types=1);

namespace Hydra\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Input
 *
 * Typed reader for a request's parsed body (form fields)
 */
final class Input extends FieldReader
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
