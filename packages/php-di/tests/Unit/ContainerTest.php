<?php

declare(strict_types=1);

namespace Hydra\PhpDi\Tests\Unit;

use DI\Container as PhpDiContainer;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\PhpDi\Container;
use PHPUnit\Framework\TestCase;
use stdClass;

interface Animal {}
final class Dog implements Animal {}

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container(new PhpDiContainer);
    }

    public function test_singleton_maps_interface_to_concrete_instance(): void
    {
        // Regression: a bare set($id, $concrete) would store the literal class
        // string, so get() returned "Dog" instead of a Dog instance.
        $this->container->singleton(Animal::class, Dog::class);

        $resolved = $this->container->get(Animal::class);

        $this->assertInstanceOf(Dog::class, $resolved);
    }

    public function test_singleton_with_closure_does_not_throw(): void
    {
        // Regression: passing the closure to \DI\autowire() (which only accepts a
        // class-name string) threw a TypeError — a callable must use \DI\factory().
        $this->container->singleton('service', fn () => new stdClass);

        $this->assertInstanceOf(stdClass::class, $this->container->get('service'));
    }

    public function test_resolved_entries_are_shared(): void
    {
        // PHP-DI caches resolved entries, so repeat resolution returns the same
        // instance — singleton() is the only binding semantics there is.
        $this->container->singleton('service', fn () => new stdClass);

        $this->assertSame(
            $this->container->get('service'),
            $this->container->get('service'),
        );
    }

    public function test_instance_registers_exact_object(): void
    {
        $object = new stdClass;
        $this->container->instance('the-object', $object);

        $this->assertSame($object, $this->container->get('the-object'));
    }

    public function test_bound_and_has_reflect_registration(): void
    {
        $this->assertFalse($this->container->bound('missing'));
        $this->assertFalse($this->container->has('missing'));

        $this->container->instance('present', new stdClass);

        $this->assertTrue($this->container->bound('present'));
        $this->assertTrue($this->container->has('present'));
    }

    public function test_create_builds_a_working_adapter_over_a_fresh_container(): void
    {
        // The zero-arg factory an app's composition root uses instead of naming
        // DI\Container itself.
        $container = Container::create();
        $container->instance('x', $object = new stdClass);

        $this->assertInstanceOf(ContainerInterface::class, $container);
        $this->assertSame($object, $container->get('x'));
    }

    public function test_implements_hydra_container_contract(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }
}
