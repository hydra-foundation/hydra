<?php

declare(strict_types=1);

namespace Hydra\Http\Tests\Unit;

use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\NegotiatingErrorRenderer;
use Hydra\Http\Responder;
use Hydra\Http\Testing\ErrorRendererContractTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;

/** The contract as an API client sees it: problem details. */
#[CoversClass(NegotiatingErrorRenderer::class)]
final class NegotiatingErrorRendererJsonTest extends ErrorRendererContractTestCase
{
    protected function renderer(): ErrorRendererInterface
    {
        $psr17 = new Psr17Factory;

        return new NegotiatingErrorRenderer(new Responder($psr17, $psr17));
    }

    protected function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/x')->withHeader('Accept', 'application/json');
    }
}
