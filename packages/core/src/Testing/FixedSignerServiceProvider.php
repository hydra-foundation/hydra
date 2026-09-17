<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Security\Signer;

/**
 * Binds a {@see Signer} under a fixed key, so a harness gets the working signer
 * the CSRF guard needs while still booting from the bare, .env-less Environment
 * integration tests deliberately use.
 *
 * The production {@see \Hydra\Core\Security\SignerServiceProvider} reads APP_KEY
 * through Environment::required(), which is the binding this one replaces.
 */
final class FixedSignerServiceProvider extends ServiceProvider
{
    /** Any 64-hex (32-byte) key; the value is irrelevant to what a test asserts. */
    public const KEY_HEX = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    public function register(ContainerInterface $container): void
    {
        $container->instance(Signer::class, Signer::fromHex(self::KEY_HEX));
    }
}
