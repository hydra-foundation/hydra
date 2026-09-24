<?php

declare(strict_types=1);

namespace Hydra\Core\Testing;

use Hydra\Core\Contracts\ExceptionReporterInterface;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * An exception reporter that keeps what it was handed, so a test can say a
 * fault was reported — and with what context — without an error tracker.
 *
 * @phpstan-type Report array{exception: Throwable, context: array<string, scalar|null>}
 */
final class FakeExceptionReporter implements ExceptionReporterInterface
{
    /** @var list<Report> */
    private array $reports = [];

    public function report(Throwable $e, array $context = []): void
    {
        $this->reports[] = ['exception' => $e, 'context' => $context];
    }

    /** @return list<Report> */
    public function reports(): array
    {
        return $this->reports;
    }

    /**
     * The first report of this exception class, or a failure naming what was
     * reported instead.
     *
     * @param class-string<Throwable> $class
     * @return Report
     */
    public function assertReported(string $class): array
    {
        foreach ($this->reports as $report) {
            if ($report['exception'] instanceof $class) {
                Assert::assertInstanceOf($class, $report['exception']);

                return $report;
            }
        }

        Assert::fail(sprintf(
            'No %s was reported. Reported: %s',
            $class,
            $this->reports === []
                ? '(nothing)'
                : implode(', ', array_map(fn (array $r) => $r['exception']::class, $this->reports)),
        ));
    }

    public function assertNothingReported(): void
    {
        Assert::assertSame(
            [],
            array_map(fn (array $r) => $r['exception']::class, $this->reports),
            'Expected no exception to be reported.',
        );
    }
}
