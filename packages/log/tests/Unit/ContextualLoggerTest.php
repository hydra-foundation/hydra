<?php

declare(strict_types=1);

namespace Hydra\Log\Tests\Unit;

use Hydra\Log\ContextualLogger;
use Hydra\Log\Testing\CapturingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(ContextualLogger::class)]
final class ContextualLoggerTest extends TestCase
{
    public function test_ambient_context_joins_every_record(): void
    {
        $inner = new CapturingLogger;

        (new ContextualLogger($inner, fn () => ['request_id' => 'r1']))->error('m', ['user' => 7]);

        $this->assertSame(
            ['level' => LogLevel::ERROR, 'message' => 'm', 'context' => ['request_id' => 'r1', 'user' => 7]],
            $inner->records()[0],
        );
    }

    public function test_the_context_is_asked_for_per_record_not_once(): void
    {
        $inner = new CapturingLogger;
        $id = 'r1';
        $logger = new ContextualLogger($inner, function () use (&$id) {
            return ['request_id' => $id];
        });

        $logger->info('a');
        $id = 'r2';
        $logger->info('b');

        $this->assertSame(['r1', 'r2'], array_map(fn ($r) => $r['context']['request_id'], $inner->records()));
    }

    public function test_a_key_the_record_sets_wins(): void
    {
        $inner = new CapturingLogger;

        (new ContextualLogger($inner, fn () => ['request_id' => 'ambient']))->info('m', ['request_id' => 'explicit']);

        $this->assertSame(['request_id' => 'explicit'], $inner->records()[0]['context']);
    }
}
