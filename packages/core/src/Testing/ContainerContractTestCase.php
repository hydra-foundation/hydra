<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use ArrayObject;
use Hydra\Core\Contracts\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;

/**
 * What every container owes a service provider, published so the one a test
 * wires by hand and the one an application boots answer the same way.
 *
 * Providers are written against three assumptions the signatures do not state:
 * a factory runs on first use and not at registration, so it may reach for a
 * binding a later provider adds; it runs once; and a later binding replaces an
 * earlier one, which is how a test provider swaps the array store in over the
 * real one.
 */
abstract class ContainerContractTestCase extends TestCase
{
    /** A container with nothing bound. */
    abstract protected function container(): ContainerInterface;

    public function test_an_instance_comes_back_as_itself(): void
    {
        $container = $this->container();
        $service = new stdClass;

        $container->instance('service', $service);

        $this->assertSame($service, $container->get('service'));
        $this->assertTrue($container->has('service'));
        $this->assertTrue($container->bound('service'));
    }

    public function test_a_factory_waits_for_the_first_get(): void
    {
        // A provider that opened its connection at registration would open it
        // on every request, including the ones that never touch it.
        $container = $this->container();
        $calls = 0;

        $container->singleton('service', function () use (&$calls): stdClass {
            $calls++;

            return new stdClass;
        });

        $this->assertSame(0, $calls);
        $this->assertTrue($container->has('service'));
    }

    public function test_a_factory_runs_once(): void
    {
        $container = $this->container();
        $calls = 0;

        $container->singleton('service', function () use (&$calls): stdClass {
            $calls++;

            return new stdClass;
        });

        $this->assertSame($container->get('service'), $container->get('service'));
        $this->assertSame(1, $calls);
    }

    public function test_a_class_name_is_built_once(): void
    {
        $container = $this->container();

        $container->singleton('service', ArrayObject::class);

        $this->assertInstanceOf(ArrayObject::class, $container->get('service'));
        $this->assertSame($container->get('service'), $container->get('service'));
    }

    public function test_a_factory_sees_a_binding_registered_after_it(): void
    {
        // Providers register in the order an application lists them, and none
        // of them should have to know that order.
        $container = $this->container();
        $container->singleton('service', static fn (): mixed => $container->get('dependency'));
        $dependency = new stdClass;

        $container->instance('dependency', $dependency);

        $this->assertSame($dependency, $container->get('service'));
    }

    public function test_a_later_instance_replaces_an_earlier_one_already_resolved(): void
    {
        $container = $this->container();
        $container->instance('service', new stdClass);
        $container->get('service');
        $replacement = new stdClass;

        $container->instance('service', $replacement);

        $this->assertSame($replacement, $container->get('service'));
    }

    public function test_a_later_factory_replaces_an_earlier_one_already_resolved(): void
    {
        // A test provider registered after the real one wins even if something
        // resolved the real binding in between. A container that kept the
        // first answer would hand that test the real store.
        $container = $this->container();
        $container->singleton('service', static fn (): stdClass => new stdClass);
        $container->get('service');

        $container->singleton('service', static fn (): ArrayObject => new ArrayObject);

        $this->assertInstanceOf(ArrayObject::class, $container->get('service'));
    }

    public function test_an_unbound_id_is_neither_had_nor_bound(): void
    {
        $container = $this->container();

        $this->assertFalse($container->has('no.such.service'));
        $this->assertFalse($container->bound('no.such.service'));
    }

    public function test_getting_an_unbound_id_throws_the_psr_not_found(): void
    {
        // PSR-11 names the type, and a caller probing for an optional service
        // catches exactly that one.
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container()->get('no.such.service');
    }
}
