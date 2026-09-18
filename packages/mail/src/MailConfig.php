<?php

declare(strict_types=1);

namespace Hydra\Mail;

use Hydra\Core\Environment;
use InvalidArgumentException;

/**
 * Mail settings, validated at construction so a bad value fails where it was
 * set rather than on the first send.
 */
final readonly class MailConfig
{
    public const SMTP = 'smtp';
    public const LOG = 'log';
    public const ARRAY = 'array';

    /** STARTTLS on a plain connection, usually port 587. */
    public const TLS = 'tls';
    /** TLS from the first byte, usually port 465. */
    public const SSL = 'ssl';
    public const NONE = 'none';

    private const TRANSPORTS = [self::SMTP, self::LOG, self::ARRAY];
    private const ENCRYPTIONS = [self::TLS, self::SSL, self::NONE];

    public ?Address $from;

    public function __construct(
        public string $transport = self::SMTP,
        public string $host = '',
        public int $port = 587,
        public string $encryption = self::TLS,
        public string $username = '',
        public string $password = '',
        public float $timeout = 10.0,
        string $fromAddress = '',
        string $fromName = '',
        public string $localDomain = 'localhost',
    ) {
        if (!in_array($transport, self::TRANSPORTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Mail transport must be one of %s; got "%s".',
                implode(', ', self::TRANSPORTS),
                $transport,
            ));
        }

        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Mail encryption must be one of %s; got "%s".',
                implode(', ', self::ENCRYPTIONS),
                $encryption,
            ));
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Mail port must be between 1 and 65535; got {$port}.");
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException("Mail timeout must be greater than 0; got {$timeout}.");
        }

        if ($transport === self::SMTP && $host === '') {
            throw new InvalidArgumentException('The smtp mail transport needs MAIL_HOST.');
        }

        if ($username !== '' && $encryption === self::NONE) {
            throw new InvalidArgumentException(
                'Mail credentials are set with MAIL_ENCRYPTION=none, which would send the password in the clear. Use tls or ssl.',
            );
        }

        $this->from = $fromAddress === '' ? null : new Address($fromAddress, $fromName);
    }

    public static function fromEnvironment(Environment $env): self
    {
        return new self(
            transport: $env->string('MAIL_TRANSPORT', self::SMTP),
            host: $env->string('MAIL_HOST', ''),
            port: $env->int('MAIL_PORT', 587),
            encryption: $env->string('MAIL_ENCRYPTION', self::TLS),
            username: $env->string('MAIL_USERNAME', ''),
            password: $env->string('MAIL_PASSWORD', ''),
            timeout: (float) $env->string('MAIL_TIMEOUT', '10'),
            fromAddress: $env->string('MAIL_FROM_ADDRESS', ''),
            fromName: $env->string('MAIL_FROM_NAME', ''),
            localDomain: $env->string('MAIL_EHLO_DOMAIN', 'localhost'),
        );
    }
}
