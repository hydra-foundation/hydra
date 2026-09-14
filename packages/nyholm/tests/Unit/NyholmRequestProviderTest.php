<?php

declare(strict_types=1);

namespace Hydra\Nyholm\Tests\Unit;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\Testing\ServerRequestProviderContractTestCase;
use Hydra\Nyholm\NyholmRequestProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The nyholm side of the request-provider seam, against the contract the kernel
 * consumes. The adapter itself is four lines; what is worth testing is that what
 * comes out of it is the request everything downstream was written against.
 */
#[CoversClass(NyholmRequestProvider::class)]
final class NyholmRequestProviderTest extends ServerRequestProviderContractTestCase
{
    protected function provider(): ServerRequestProviderInterface
    {
        return NyholmRequestProvider::create(new Psr17Factory);
    }

    public function test_fulfils_the_request_provider_seam(): void
    {
        $this->assertInstanceOf(ServerRequestProviderInterface::class, $this->provider());
    }
}
