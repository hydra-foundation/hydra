<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Support;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Records every request, and answers a path with its route or a bare 200. */
final class RecordingHandler implements RequestHandlerInterface
{
    /** @var list<ServerRequestInterface> */
    public array $seen = [];

    /** @var array<string, ResponseInterface> */
    public array $routes = [];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->seen[] = $request;

        return $this->routes[$request->getUri()->getPath()] ?? new Response(200);
    }
}
