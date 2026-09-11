<?php

declare(strict_types=1);

namespace Hydra\Event\Tests\Unit;

use Hydra\Event\ListenerProvider;
use PHPUnit\Framework\TestCase;

final class ListenerProviderTest extends TestCase
{
    private ListenerProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ListenerProvider;
    }

    public function test_no_listeners_yields_nothing(): void
    {
        $this->assertSame([], $this->collect(new Leaf));
    }

    public function test_matches_the_exact_class(): void
    {
        $this->provider->listen(Leaf::class, $a = fn () => null);

        $this->assertSame([$a], $this->collect(new Leaf));
    }

    public function test_matches_a_base_class_via_instanceof(): void
    {
        // A listener on the base type fires for a subtype — the PSR-14 idiom that
        // lets an app subscribe to a whole family of events at once.
        $this->provider->listen(Base::class, $a = fn () => null);

        $this->assertSame([$a], $this->collect(new Leaf));
    }

    public function test_matches_a_marker_interface(): void
    {
        $this->provider->listen(Marker::class, $a = fn () => null);

        $this->assertSame([$a], $this->collect(new Leaf));
    }

    public function test_does_not_match_an_unrelated_type(): void
    {
        $this->provider->listen(Base::class, fn () => null);

        $this->assertSame([], $this->collect(new Unrelated));
    }

    public function test_preserves_registration_order_across_types(): void
    {
        $this->provider->listen(Leaf::class, $a = fn () => null);
        $this->provider->listen(Base::class, $b = fn () => null);

        // Both match a Leaf; they come back in the order their types were registered.
        $this->assertSame([$a, $b], $this->collect(new Leaf));
    }

    /**
     * @return list<callable>
     */
    private function collect(object $event): array
    {
        return iterator_to_array($this->provider->getListenersForEvent($event), false);
    }
}

interface Marker
{
}

abstract class Base
{
}

final class Leaf extends Base implements Marker
{
}

final class Unrelated
{
}
