<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\FakeStore;
use Hydra\Cache\Testing\StoreContractTestCase;
use Hydra\Core\Testing\FrozenClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The fake against the contract every store answers, plus the failures it
 * exists for.
 */
#[CoversClass(FakeStore::class)]
final class FakeStoreTest extends StoreContractTestCase
{
    private FrozenClock $clock;

    private FakeStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
        $this->store = new FakeStore(ArrayStore::withClock($this->clock));
    }

    protected function store(): StoreInterface
    {
        return $this->store;
    }

    protected function advance(int $seconds): void
    {
        $this->clock->advance("+{$seconds} seconds");
    }

    /** @return array<string, array{string, list<mixed>}> */
    public static function operations(): array
    {
        return [
            'get' => ['get', ['rate:1']],
            'put' => ['put', ['rate:1', 1]],
            'forget' => ['forget', ['rate:1']],
            'increment' => ['increment', ['rate:1']],
            'ttl' => ['ttl', ['rate:1']],
            'flush' => ['flush', []],
        ];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('operations')]
    public function test_fail_all_fails_every_operation_with_a_refused_connection(string $method, array $arguments): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');

        (new FakeStore)->failAll()->{$method}(...$arguments);
    }

    public function test_fail_on_fails_only_keys_containing_it(): void
    {
        $store = (new FakeStore)->failOn('rate:');
        $store->put('session:1', 'kept');

        $this->assertSame('kept', $store->get('session:1'));

        $this->expectException(RuntimeException::class);
        $store->increment('rate:1');
    }

    public function test_fail_on_leaves_flush_alone(): void
    {
        $store = (new FakeStore)->failOn('rate:');
        $store->put('session:1', 'gone');

        $store->flush();

        $this->assertNull($store->get('session:1'));
    }

    public function test_the_failure_given_is_the_one_thrown(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('read only');

        (new FakeStore)->failOn('rate:', new LogicException('read only'))->put('rate:1', 1);
    }

    public function test_a_failed_write_never_reaches_the_store(): void
    {
        $inner = new ArrayStore;

        try {
            (new FakeStore($inner))->failAll()->put('k', 'v');
        } catch (RuntimeException) {
        }

        $this->assertNull($inner->get('k'));
    }
}
