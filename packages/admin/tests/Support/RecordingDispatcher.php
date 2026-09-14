<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A dispatcher that keeps what it was handed. The admin depends on the PSR
 * interface and not on hydrakit/event, and so does its test suite: what is
 * being asserted is that the admin announced the right thing, not how some
 * other package delivers it.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}
