<?php

declare(strict_types=1);

namespace Hydra\Http;

use Hydra\Http\Contracts\ArgumentResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a callable into a PSR-15 request handler
 */
final class CallableHandler implements RequestHandlerInterface
{
    /** @var callable */
    private $handler;

    /** @param array<string, string> $routeParams matched placeholders for this request */
    public function __construct(
        callable $handler,
        private readonly ArgumentResolverInterface $arguments,
        private readonly array $routeParams = [],
    ) {
        $this->handler = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->handler)(...$this->arguments->resolve($this->handler, $request, $this->routeParams));
    }
}
