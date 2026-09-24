<?php

declare(strict_types=1);

namespace Hydra\Core\Tests\Unit;

use Hydra\Core\Testing\FakeExceptionReporter;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeExceptionReporter::class)]
final class FakeExceptionReporterTest extends TestCase
{
    public function test_keeps_each_report_with_its_context(): void
    {
        $reporter = new FakeExceptionReporter;
        $first = new RuntimeException('a');
        $second = new LogicException('b');

        $reporter->report($first, ['job' => 'SendMail']);
        $reporter->report($second);

        $this->assertSame([
            ['exception' => $first, 'context' => ['job' => 'SendMail']],
            ['exception' => $second, 'context' => []],
        ], $reporter->reports());
    }

    public function test_assert_reported_returns_the_first_match(): void
    {
        $reporter = new FakeExceptionReporter;
        $e = new RuntimeException('a');
        $reporter->report(new LogicException('b'));
        $reporter->report($e, ['task' => 'Prune']);

        $this->assertSame(['exception' => $e, 'context' => ['task' => 'Prune']], $reporter->assertReported(RuntimeException::class));
    }

    public function test_assert_reported_names_what_was_reported_instead(): void
    {
        $reporter = new FakeExceptionReporter;
        $reporter->report(new LogicException('b'));

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No RuntimeException was reported. Reported: LogicException');

        $reporter->assertReported(RuntimeException::class);
    }

    public function test_assert_reported_on_an_empty_reporter_says_nothing_was(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Reported: (nothing)');

        (new FakeExceptionReporter)->assertReported(RuntimeException::class);
    }

    public function test_assert_nothing_reported(): void
    {
        $reporter = new FakeExceptionReporter;
        $reporter->assertNothingReported();

        $reporter->report(new LogicException('b'));

        $this->expectException(AssertionFailedError::class);
        $reporter->assertNothingReported();
    }
}
