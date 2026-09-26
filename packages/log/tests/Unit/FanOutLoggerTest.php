<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Log\FanOutLogger;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

#[CoversClass(FanOutLogger::class)]
final class FanOutLoggerTest extends TestCase
{
    public function test_every_record_reaches_every_logger(): void
    {
        $file = new CapturingLogger;
        $stderr = new CapturingLogger;

        (new FanOutLogger($file, $stderr))->warning('Disk {pct} full', ['pct' => 91]);

        $expected = [['level' => LogLevel::WARNING, 'message' => 'Disk {pct} full', 'context' => ['pct' => 91]]];
        $this->assertSame($expected, $file->records());
        $this->assertSame($expected, $stderr->records());
    }

    public function test_a_logger_that_throws_does_not_stop_the_rest(): void
    {
        $after = new CapturingLogger;
        $broken = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk gone');
            }
        };

        (new FanOutLogger($broken, $after))->error('still said');

        $this->assertSame('still said', $after->records()[0]['message']);
    }
}
