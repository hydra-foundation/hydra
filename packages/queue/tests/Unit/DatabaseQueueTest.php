<?php

declare(strict_types=1);

namespace Hydra\Queue\Tests\Unit;

use Hydra\Core\Testing\FrozenClock;
use Hydra\Database\Testing\FakeConnection;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\Payload;
use Hydra\Queue\ReservedJob;
use Hydra\Queue\Tests\Support\QueueTables;
use Hydra\Queue\Tests\Support\RecordingJob;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseQueue::class)]
#[CoversClass(ReservedJob::class)]
#[CoversClass(Payload::class)]
final class DatabaseQueueTest extends TestCase
{
    private FakeConnection $db;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->db = FakeConnection::inMemory();
        QueueTables::create($this->db);
        $this->clock = new FrozenClock('2026-09-23T04:00:00+00:00');
    }

    public function test_a_push_is_stored_as_json_available_after_its_delay(): void
    {
        $now = $this->clock->now()->getTimestamp();

        $this->queue()->push(RecordingJob::class, ['id' => 7, 'ratio' => 1.0, 'url' => 'https://x.test/a'], delay: 30);

        $this->assertSame(
            [['job' => RecordingJob::class, 'payload' => '{"id":7,"ratio":1.0,"url":"https://x.test/a"}', 'attempts' => 0, 'available_at' => $now + 30, 'reserved_at' => null, 'created_at' => $now]],
            $this->db->select('SELECT job, payload, attempts, available_at, reserved_at, created_at FROM jobs'),
        );
    }

    public function test_reserving_claims_available_jobs_oldest_first_and_counts_the_attempt(): void
    {
        $this->queue()->push(RecordingJob::class, ['n' => 1]);
        $this->queue()->push(RecordingJob::class, ['n' => 2]);
        $this->queue()->push(RecordingJob::class, ['n' => 3]);

        $jobs = $this->queue()->reserve(2);

        $this->assertSame([['n' => 1], ['n' => 2]], array_map(static fn (ReservedJob $j): array => $j->payload(), $jobs));
        $this->assertSame([1, 1], array_map(static fn (ReservedJob $j): int => $j->attempts, $jobs));
        $this->assertSame(RecordingJob::class, $jobs[0]->job);
        $this->assertSame($jobs[0]->reservation, $jobs[1]->reservation);
        $this->assertCount(1, $this->queue()->reserve(10));
        $this->assertSame([], $this->queue()->reserve(10));
    }

    public function test_a_delayed_job_waits_for_its_time(): void
    {
        $this->queue()->push(RecordingJob::class, delay: 60);

        $this->assertSame([], $this->queue()->reserve(10));

        $this->clock->advance('+60 seconds');

        $this->assertCount(1, $this->queue()->reserve(10));
    }

    public function test_a_claim_nobody_finished_is_taken_again_once_stale(): void
    {
        $this->queue()->push(RecordingJob::class);
        [$first] = $this->queue()->reserve(1);

        $this->clock->advance('+899 seconds');
        $this->assertSame([], $this->queue()->reserve(1));

        $this->clock->advance('+1 second');
        [$second] = $this->queue()->reserve(1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->attempts);
        $this->assertNotSame($first->reservation, $second->reservation);
    }

    public function test_a_stale_holder_cannot_touch_the_claim_that_replaced_it(): void
    {
        $this->queue()->push(RecordingJob::class);
        [$stale] = $this->queue()->reserve(1);
        $this->clock->advance('+15 minutes');
        [$current] = $this->queue()->reserve(1);

        $this->queue()->delete($stale);
        $this->queue()->release($stale, 0);
        $this->queue()->fail($stale, new RuntimeException('late'));

        $this->assertSame($current->reservation, $this->db->selectOne('SELECT reservation FROM jobs')['reservation'] ?? null);
        $this->assertSame([], $this->db->select('SELECT id FROM failed_jobs'));
    }

    public function test_delete_removes_a_finished_job(): void
    {
        $this->queue()->push(RecordingJob::class);
        [$job] = $this->queue()->reserve(1);

        $this->queue()->delete($job);

        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
    }

    public function test_release_frees_the_job_after_the_delay(): void
    {
        $this->queue()->push(RecordingJob::class);
        [$job] = $this->queue()->reserve(1);

        $this->queue()->release($job, 10);

        $this->assertSame([], $this->queue()->reserve(1));
        $this->clock->advance('+10 seconds');
        [$again] = $this->queue()->reserve(1);
        $this->assertSame(2, $again->attempts);
    }

    public function test_fail_moves_the_job_and_its_exception_to_failed_jobs(): void
    {
        $this->queue()->push(RecordingJob::class, ['id' => 7]);
        [$job] = $this->queue()->reserve(1);

        $this->queue()->fail($job, new RuntimeException('the mail server is down'));

        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
        $failed = $this->db->selectOne('SELECT job, payload, exception, failed_at FROM failed_jobs');
        $this->assertNotNull($failed);
        $this->assertSame(RecordingJob::class, $failed['job']);
        $this->assertSame('{"id":7}', $failed['payload']);
        $this->assertStringStartsWith('RuntimeException: the mail server is down', (string) $failed['exception']);
        $this->assertSame($this->clock->now()->getTimestamp(), $failed['failed_at']);
    }

    public function test_mariadb_claims_with_skip_locked_and_sqlite_does_not(): void
    {
        $this->queue()->push(RecordingJob::class);
        $this->queue()->reserve(1);
        $this->db->assertNotRan('SKIP LOCKED');

        $this->db->failOn('SKIP LOCKED', new PDOException('stop here'));

        foreach (['mysql', 'mariadb'] as $driver) {
            try {
                (new DatabaseQueue($this->db, $this->clock, $driver))->reserve(1);
                $this->fail("The {$driver} claim did not reach the database.");
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(2, array_filter(
            $this->db->statements(),
            static fn (array $statement): bool => str_ends_with($statement['sql'], 'ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED'),
        ));
    }

    public function test_a_malformed_payload_fails_as_its_own_job(): void
    {
        $this->db->execute("INSERT INTO jobs (job, payload, attempts, available_at, created_at) VALUES ('X', '\"text\"', 0, 0, 0)");
        [$job] = $this->queue()->reserve(1);

        $this->expectExceptionMessage('decoded to string rather than an array');

        $job->payload();
    }

    public function test_limits_must_be_at_least_one(): void
    {
        foreach ([
            fn () => $this->queue()->reserve(0),
            fn () => new DatabaseQueue($this->db, $this->clock, 'sqlite', reserveFor: 0),
        ] as $call) {
            try {
                $call();
                $this->fail('Zero was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function queue(): DatabaseQueue
    {
        return new DatabaseQueue($this->db, $this->clock, 'sqlite');
    }
}
