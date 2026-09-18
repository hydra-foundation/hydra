<?php

declare(strict_types=1);

namespace Hydra\Mail;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Transports\ArrayTransport;
use Hydra\Mail\Transports\LogTransport;
use Hydra\Mail\Transports\SmtpTransport;
use Psr\Log\LoggerInterface;

/**
 * Wires the mail package into an application. Nothing is configured or
 * connected until something asks for the mailer.
 */
final class MailServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(MailConfig::class, function () use ($container) {
            return MailConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(TransportInterface::class, function () use ($container) {
            $config = $container->get(MailConfig::class);

            return match ($config->transport) {
                MailConfig::LOG => new LogTransport($container->get(LoggerInterface::class)),
                MailConfig::ARRAY => new ArrayTransport,
                default => new SmtpTransport($config),
            };
        });

        $container->singleton(MailerInterface::class, function () use ($container) {
            return new Mailer(
                $container->get(TransportInterface::class),
                $container->get(MailConfig::class)->from,
            );
        });
    }
}
