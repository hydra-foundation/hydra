<?php

declare(strict_types=1);

namespace Hydra\Csrf\Testing;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Csrf\CsrfGuard;
use Hydra\Http\Testing\RequestPreparer;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An unsafe request carries the session's own token, minted inside a started
 * session the way a rendered form would have minted it.
 */
final class CarriesCsrfToken implements RequestPreparer
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly CsrfGuard $guard,
        private readonly SessionLifecycleInterface $session,
    ) {}

    public static function for(ContainerInterface $container): self
    {
        return new self($container->get(CsrfGuard::class), $container->get(SessionLifecycleInterface::class));
    }

    public function prepare(ServerRequestInterface $request): ServerRequestInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE, true) || $request->hasHeader(CsrfGuard::HEADER)) {
            return $request;
        }

        $this->session->start();

        return $request->withHeader(CsrfGuard::HEADER, $this->guard->token());
    }
}
