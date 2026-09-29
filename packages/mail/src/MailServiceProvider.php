<?php

declare(strict_types=1);

namespace Hydra\Mail;

use Hydra\Core\Clock\SystemClock;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\Transports\ArrayTransport;
use Hydra\Mail\Transports\LogTransport;
use Hydra\Mail\Transports\SmtpTransport;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
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

        $container->singleton(MimeRenderer::class, fn () => new MimeRenderer($this->clock($container)));

        $container->singleton(TransportInterface::class, function () use ($container) {
            $config = $container->get(MailConfig::class);

            return match ($config->transport) {
                MailConfig::LOG => new LogTransport($container->get(LoggerInterface::class)),
                MailConfig::ARRAY => new ArrayTransport,
                default => new SmtpTransport($config, $container->get(MimeRenderer::class)),
            };
        });

        $container->singleton(MailerInterface::class, function () use ($container) {
            $config = $container->get(MailConfig::class);

            // The dispatcher is OPTIONAL: an app that binds one hears every
            // sent message; one that doesn't gets the mailer as it always was.
            return new Mailer(
                $container->get(TransportInterface::class),
                $config->from,
                $container->bound(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
                $config->transport,
            );
        });
    }

    /** The bound clock, or the system's when nothing registered one. */
    private function clock(ContainerInterface $container): ClockInterface
    {
        return $container->bound(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock;
    }
}
