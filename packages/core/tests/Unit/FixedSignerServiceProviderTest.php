<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Security\Encrypter;
use Hydra\Core\Security\Signer;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FixedSignerServiceProvider::class)]
final class FixedSignerServiceProviderTest extends TestCase
{
    public function test_the_signer_uses_the_published_key(): void
    {
        // Published so a test can sign something itself and have the
        // application's signer accept it.
        $container = new FakeContainer;
        (new FixedSignerServiceProvider)->register($container);

        $signed = Signer::fromHex(FixedSignerServiceProvider::KEY_HEX)->sign('message');

        $this->assertSame('message', $container->get(Signer::class)->verify($signed));
    }

    public function test_the_encrypter_uses_the_published_key(): void
    {
        $container = new FakeContainer;
        (new FixedSignerServiceProvider)->register($container);

        $sealed = Encrypter::fromHex(FixedSignerServiceProvider::KEY_HEX)->encrypt('message');

        $this->assertSame('message', $container->get(Encrypter::class)->decrypt($sealed));
    }
}
