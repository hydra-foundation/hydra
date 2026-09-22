<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Mail\Contracts\MailerInterface;
use Hydra\Mail\Contracts\TransportInterface;
use Hydra\Mail\MailConfig;
use Hydra\Mail\MailServiceProvider;
use Hydra\Mail\Mailer;
use Hydra\Mail\Message;
use Hydra\Mail\MimeRenderer;
use Hydra\Mail\Testing\FakeMailServiceProvider;
use Hydra\Mail\Testing\FakeMailer;
use Hydra\Core\Testing\FakeContainer;
use Hydra\Mail\Transports\ArrayTransport;
use Hydra\Mail\Transports\LogTransport;
use Hydra\Mail\Transports\SmtpTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(MailServiceProvider::class)]
#[CoversClass(FakeMailServiceProvider::class)]
final class MailServiceProviderTest extends TestCase
{
    private function container(MailConfig $config): FakeContainer
    {
        $container = new FakeContainer([LoggerInterface::class => new CapturingLogger]);
        (new MailServiceProvider)->register($container);
        $container->instance(MailConfig::class, $config);

        return $container;
    }

    public function test_each_transport_setting_binds_its_transport(): void
    {
        $this->assertInstanceOf(SmtpTransport::class, $this->container(new MailConfig(host: 'h'))->get(TransportInterface::class));
        $this->assertInstanceOf(LogTransport::class, $this->container(new MailConfig(transport: 'log'))->get(TransportInterface::class));
        $this->assertInstanceOf(ArrayTransport::class, $this->container(new MailConfig(transport: 'array'))->get(TransportInterface::class));
    }

    public function test_the_renderer_dates_mail_by_the_bound_clock(): void
    {
        $container = $this->container(new MailConfig(host: 'h'));
        $container->instance(ClockInterface::class, new FrozenClock('2026-03-04T05:06:07+00:00'));

        $mime = $container->get(MimeRenderer::class)->render(
            Message::make()->from('app@example.com')->to('ada@example.com')->subject('Hi')->text('Hi'),
        );

        $this->assertStringStartsWith("Date: Wed, 04 Mar 2026 05:06:07 +0000\r\n", $mime);
    }

    public function test_the_renderer_falls_back_to_the_system_clock(): void
    {
        $this->assertInstanceOf(MimeRenderer::class, $this->container(new MailConfig(host: 'h'))->get(MimeRenderer::class));
    }

    public function test_the_mailer_sends_through_the_bound_transport_with_the_configured_sender(): void
    {
        $container = $this->container(new MailConfig(transport: 'array', fromAddress: 'app@example.com'));

        $mailer = $container->get(MailerInterface::class);
        $this->assertInstanceOf(Mailer::class, $mailer);
        $mailer->send(Message::make()->to('a@example.com')->text('x'));

        $transport = $container->get(TransportInterface::class);
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertSame('app@example.com', $transport->messages()[0]->getFrom()?->email);
    }

    public function test_nothing_is_configured_until_the_mailer_is_asked_for(): void
    {
        $container = new FakeContainer([Environment::class => new Environment(sys_get_temp_dir())]);
        (new MailServiceProvider)->register($container);

        $this->assertFalse($container->isResolved(MailConfig::class));
        $this->assertFalse($container->isResolved(MailerInterface::class));
    }

    public function test_the_fake_replaces_the_mailer_and_is_reachable_for_assertions(): void
    {
        $container = $this->container(new MailConfig(transport: 'array'));
        (new FakeMailServiceProvider)->register($container);

        $this->assertInstanceOf(FakeMailer::class, $container->get(MailerInterface::class));
        $this->assertSame($container->get(FakeMailer::class), $container->get(MailerInterface::class));
    }
}
