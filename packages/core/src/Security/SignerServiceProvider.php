<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Binds the application APP_KEY
 */
final class SignerServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(Signer::class, function () use ($container) {
            $environment = $container->get(Environment::class);
            return Signer::fromHex($environment->required('APP_KEY'));
        });
    }
}
