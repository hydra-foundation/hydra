<?php

declare(strict_types=1);

namespace Hydra\Mail\Tests\Unit;

use Hydra\Core\Environment;
use Hydra\Mail\MailConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailConfig::class)]
final class MailConfigTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown transport' => [['transport' => 'sendmail'], 'Mail transport must be one of smtp, log, array; got "sendmail".'];
        yield 'unknown encryption' => [['encryption' => 'starttls'], 'Mail encryption must be one of tls, ssl, none; got "starttls".'];
        yield 'port zero' => [['port' => 0], 'Mail port must be between 1 and 65535; got 0.'];
        yield 'port too high' => [['port' => 65536], 'Mail port must be between 1 and 65535; got 65536.'];
        yield 'no timeout' => [['timeout' => 0.0], 'Mail timeout must be greater than 0; got 0.'];
        yield 'smtp without a host' => [['host' => ''], 'The smtp mail transport needs MAIL_HOST.'];
        yield 'a password in the clear' => [['encryption' => 'none', 'username' => 'u'], 'would send the password in the clear'];
        yield 'a bad sender' => [['fromAddress' => 'nope'], '"nope" is not an email address.'];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalid')]
    public function test_a_bad_setting_fails_at_construction(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new MailConfig(...[...['host' => 'mail.example.com'], ...$arguments]);
    }

    public function test_log_and_array_need_no_host(): void
    {
        $this->assertSame('log', (new MailConfig(transport: 'log'))->transport);
        $this->assertSame('array', (new MailConfig(transport: 'array'))->transport);
    }

    public function test_the_sender_is_optional(): void
    {
        $this->assertNull((new MailConfig(transport: 'log'))->from);
    }

    public function test_it_reads_the_environment(): void
    {
        $dir = sys_get_temp_dir() . '/hydra-mail-env-' . uniqid('', true);
        mkdir($dir);
        $keys = [
            'MAIL_TRANSPORT' => 'smtp', 'MAIL_HOST' => 'mailpit', 'MAIL_PORT' => '1025', 'MAIL_ENCRYPTION' => 'none',
            'MAIL_TIMEOUT' => '2.5', 'MAIL_FROM_ADDRESS' => 'app@example.com', 'MAIL_FROM_NAME' => 'App',
            'MAIL_EHLO_DOMAIN' => 'app.test',
        ];
        file_put_contents($dir . '/.env', implode("\n", array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($keys), $keys)));

        try {
            $config = MailConfig::fromEnvironment(new Environment($dir));
        } finally {
            foreach (array_keys($keys) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            unlink($dir . '/.env');
            rmdir($dir);
        }

        $this->assertSame('mailpit', $config->host);
        $this->assertSame(1025, $config->port);
        $this->assertSame('none', $config->encryption);
        $this->assertSame(2.5, $config->timeout);
        $this->assertNotNull($config->from);
        $this->assertSame('app@example.com', $config->from->email);
        $this->assertSame('App', $config->from->name);
        $this->assertSame('app.test', $config->localDomain);
    }
}
