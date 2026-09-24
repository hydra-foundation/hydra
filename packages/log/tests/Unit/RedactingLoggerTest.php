<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Log\RedactingLogger;
use Hydra\Log\StreamLogger;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;

#[CoversClass(RedactingLogger::class)]
final class RedactingLoggerTest extends TestCase
{
    private CapturingLogger $inner;

    protected function setUp(): void
    {
        $this->inner = new CapturingLogger;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string>|null $fields
     * @return array<string, mixed>
     */
    private function logged(array $context, ?array $fields = null): array
    {
        $logger = $fields === null
            ? new RedactingLogger($this->inner)
            : new RedactingLogger($this->inner, $fields);

        $logger->info('m', $context);

        return $this->inner->records()[0]['context'];
    }

    /** @return iterable<string, array{string}> */
    public static function secretKeys(): iterable
    {
        foreach ([
            'password', 'current_password', 'Password-Confirmation', 'PASSWD',
            'client_secret', 'remember_token', '_csrf', 'Authorization',
            'Set-Cookie', 'X-Api-Key', 'api_key',
        ] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('secretKeys')]
    public function test_a_key_naming_a_secret_is_blanked(string $key): void
    {
        $this->assertSame([$key => RedactingLogger::REDACTED], $this->logged([$key => 'hunter2']));
    }

    public function test_harmless_keys_pass_through_untouched(): void
    {
        $context = ['email' => 'a@b.c', 'status' => 302, 'path' => '/sign-in', 'key' => 'cache:x'];

        $this->assertSame($context, $this->logged($context));
    }

    public function test_nested_arrays_are_searched_at_any_depth(): void
    {
        $logged = $this->logged([
            'body' => ['email' => 'a@b.c', 'password' => 'hunter2', 'profile' => ['api_key' => 'k']],
            'rows' => [['token' => 't1'], ['token' => 't2']],
        ]);

        $this->assertSame([
            'body' => ['email' => 'a@b.c', 'password' => RedactingLogger::REDACTED, 'profile' => ['api_key' => RedactingLogger::REDACTED]],
            'rows' => [['token' => RedactingLogger::REDACTED], ['token' => RedactingLogger::REDACTED]],
        ], $logged);
    }

    public function test_a_denied_key_holding_an_array_is_blanked_whole(): void
    {
        $this->assertSame(['cookies' => RedactingLogger::REDACTED], $this->logged(['cookies' => ['sid' => 'x']]));
    }

    public function test_the_exception_is_handed_on_intact(): void
    {
        $e = new RuntimeException('boom');

        $this->assertSame(['exception' => $e], $this->logged(['exception' => $e]));
    }

    public function test_the_denylist_can_be_extended(): void
    {
        $logged = $this->logged(['iban' => 'DE89', 'password' => 'p'], [...RedactingLogger::FIELDS, 'IBAN']);

        $this->assertSame(['iban' => RedactingLogger::REDACTED, 'password' => RedactingLogger::REDACTED], $logged);
    }

    public function test_level_and_message_reach_the_inner_logger(): void
    {
        (new RedactingLogger($this->inner))->warning('signed in {email}', ['email' => 'a@b.c']);

        $record = $this->inner->records()[0];
        $this->assertSame(LogLevel::WARNING, $record['level']);
        $this->assertSame('signed in {email}', $record['message']);
    }

    public function test_a_placeholder_for_a_secret_interpolates_the_redacted_value(): void
    {
        $stream = fopen('php://memory', 'r+');
        (new RedactingLogger(new StreamLogger($stream)))->info('reset with {token}', ['token' => 'abc123']);

        rewind($stream);
        $line = stream_get_contents($stream);

        $this->assertStringContainsString('reset with [redacted]', $line);
        $this->assertStringNotContainsString('abc123', $line);
    }
}
