<?php

declare(strict_types=1);

namespace Hydra\Mail\Testing;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Mail\Contracts\MailerInterface;

/**
 * Test counterpart to {@see \Hydra\Mail\MailServiceProvider}: binds a
 * {@see FakeMailer}, resolvable as itself for the assertions. Register it after
 * the real provider.
 */
final class FakeMailServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $mailer = new FakeMailer;

        $container->instance(FakeMailer::class, $mailer);
        $container->instance(MailerInterface::class, $mailer);
    }
}
