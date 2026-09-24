<?php

declare(strict_types=1);

namespace Hydra\Cache\Tests\Unit;

use Hydra\Cache\RedisStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * When the connection opens, and what an unreachable server costs a request.
 * No server needed: every opener here fails before it would reach one.
 */
#[CoversClass(RedisStore::class)]
final class RedisStoreOpeningTest extends TestCase
{
    public function test_building_the_store_does_not_connect(): void
    {
        $opened = 0;

        $store = new RedisStore(function () use (&$opened) {
            $opened++;

            throw new RuntimeException('should not be reached');
        });

        $this->assertInstanceOf(RedisStore::class, $store);
        $this->assertSame(0, $opened);
    }

    public function test_the_first_command_opens_and_a_failed_open_is_thrown_again_without_retrying(): void
    {
        $opened = 0;
        $failure = new RuntimeException('Could not connect to Redis at redis:6379.');
        $store = new RedisStore(function () use (&$opened, $failure) {
            $opened++;

            throw $failure;
        }, 'app:');

        foreach ([fn () => $store->get('a'), fn () => $store->increment('b'), fn () => $store->put('c', 1)] as $command) {
            try {
                $command();
                $this->fail('A store that cannot open must throw.');
            } catch (RuntimeException $e) {
                $this->assertSame($failure, $e);
            }
        }

        $this->assertSame(1, $opened);
    }
}
