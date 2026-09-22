<?php

declare(strict_types=1);

namespace Hydra\PhpDi\Tests\Unit;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Testing\ContainerContractTestCase;
use Hydra\PhpDi\Container;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Container::class)]
final class ContainerContractTest extends ContainerContractTestCase
{
    protected function container(): ContainerInterface
    {
        return Container::create();
    }
}
