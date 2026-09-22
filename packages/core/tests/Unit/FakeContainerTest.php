<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Testing\ContainerContractTestCase;
use Hydra\Core\Testing\FakeContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

#[CoversClass(FakeContainer::class)]
final class FakeContainerTest extends ContainerContractTestCase
{
    protected function container(): ContainerInterface
    {
        return new FakeContainer;
    }

    public function test_instances_given_up_front_are_bound(): void
    {
        $service = new stdClass;

        $this->assertSame($service, (new FakeContainer(['service' => $service]))->get('service'));
    }

    public function test_is_resolved_tells_registered_from_built(): void
    {
        $container = new FakeContainer;
        $container->singleton('service', static fn (): stdClass => new stdClass);

        $this->assertFalse($container->isResolved('service'));
        $container->get('service');
        $this->assertTrue($container->isResolved('service'));
    }

    public function test_the_not_found_message_names_the_id(): void
    {
        $this->expectExceptionMessage('Nothing is bound to no.such.service.');
        (new FakeContainer)->get('no.such.service');
    }
}
