<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

#[CoversClass(CapturingLogger::class)]
final class CapturingLoggerTest extends TestCase
{
    public function test_it_keeps_every_record_in_order(): void
    {
        $logger = new CapturingLogger;
        $logger->info('first', ['id' => 1]);
        $logger->error('second');

        $this->assertSame([
            ['level' => LogLevel::INFO, 'message' => 'first', 'context' => ['id' => 1]],
            ['level' => LogLevel::ERROR, 'message' => 'second', 'context' => []],
        ], $logger->records());
        $this->assertSame(['first', 'second'], $logger->messages());
    }

    public function test_a_stringable_message_is_kept_as_its_string(): void
    {
        $logger = new CapturingLogger;
        $logger->warning(new class implements Stringable {
            public function __toString(): string
            {
                return 'stringified';
            }
        });

        $this->assertSame(['stringified'], $logger->messages());
    }

    public function test_has_is_an_exact_match(): void
    {
        $logger = new CapturingLogger;
        $logger->info('request.handled');

        $this->assertTrue($logger->has('request.handled'));
        $this->assertFalse($logger->has('request'));
    }

    public function test_first_with_returns_the_earliest_matching_record(): void
    {
        $logger = new CapturingLogger;
        $logger->info('login', ['user' => 'ada']);
        $logger->info('login', ['user' => 'grace']);

        $this->assertSame(['user' => 'ada'], $logger->firstWith('login')['context']);
    }

    public function test_first_with_names_what_was_logged_instead(): void
    {
        $logger = new CapturingLogger;
        $logger->info('login');
        $logger->info('logout');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No 'failed' record was logged. Logged: login, logout");
        $logger->firstWith('failed');
    }

    public function test_first_with_on_an_empty_log_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No 'failed' record was logged. Logged: (nothing)");
        (new CapturingLogger)->firstWith('failed');
    }

    public function test_clear_forgets_everything(): void
    {
        $logger = new CapturingLogger;
        $logger->info('first');
        $logger->clear();

        $this->assertSame([], $logger->records());
    }
}
