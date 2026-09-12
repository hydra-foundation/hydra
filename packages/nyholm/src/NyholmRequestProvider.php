<?php

declare(strict_types=1);

namespace Hydra\Nyholm;

use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adapts nyholm's ServerRequestCreator to Hydra's request-provider seam
 */
final class NyholmRequestProvider implements ServerRequestProviderInterface
{
    public function __construct(private readonly ServerRequestCreator $creator) {}

    public static function create(Psr17Factory $factory): self
    {
        return new self(new ServerRequestCreator($factory, $factory, $factory, $factory));
    }

    public function fromGlobals(): ServerRequestInterface
    {
        return $this->creator->fromGlobals();
    }
}
