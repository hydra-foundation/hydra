<?php

declare(strict_types=1);

namespace Hydra\Nyholm\Tests\Unit;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Nyholm\NyholmRequestProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class NyholmRequestProviderTest extends TestCase
{
    public function test_fulfils_the_request_provider_seam(): void
    {
        $provider = NyholmRequestProvider::create(new Psr17Factory);

        $this->assertInstanceOf(ServerRequestProviderInterface::class, $provider);
    }

    public function test_from_globals_builds_a_server_request_from_the_environment(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/widgets?page=2';
        $_SERVER['HTTP_HOST'] = 'hydra.test';

        $request = NyholmRequestProvider::create(new Psr17Factory)->fromGlobals();

        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/widgets', $request->getUri()->getPath());
        $this->assertSame('hydra.test', $request->getUri()->getHost());
    }
}
