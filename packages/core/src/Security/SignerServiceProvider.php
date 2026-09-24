<?php

declare(strict_types=1);

namespace Hydra\Core\Security;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Binds the Signer and the Encrypter over APP_KEY, still accepting what
 * APP_PREVIOUS_KEYS signed or sealed. Required rather than defaulted: an app
 * with no key must fail at boot, not sign with a predictable one.
 */
final class SignerServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(Signer::class, function () use ($container) {
            $environment = $container->get(Environment::class);
            return Signer::fromHex($environment->required('APP_KEY'), $environment->list('APP_PREVIOUS_KEYS'));
        });

        $container->singleton(Encrypter::class, function () use ($container) {
            $environment = $container->get(Environment::class);
            return Encrypter::fromHex($environment->required('APP_KEY'), $environment->list('APP_PREVIOUS_KEYS'));
        });
    }
}
