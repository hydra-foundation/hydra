<?php

declare(strict_types=1);

namespace Hydra\Kernel\Tests\Support;

use Hydra\Http\Attributes\Route;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** A no-dependency controller so the router can resolve it from the strict test container. */
final class StubController
{
    #[Route('/ping')]
    public function ping(): ResponseInterface
    {
        return new Response(200, [], 'pong');
    }
}
