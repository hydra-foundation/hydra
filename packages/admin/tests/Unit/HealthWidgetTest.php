<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Widgets\CacheWidget;
use Hydra\Admin\Widgets\ResourcesWidget;
use Hydra\Admin\Widgets\Status;
use Hydra\Admin\Widgets\UptimeWidget;
use Hydra\Cache\ArrayStore;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Cache\Testing\FakeStore;
use Hydra\Core\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The health cards the package ships, and the one property they share: a probe
 * that fails reports the failure rather than raising it. A presenter that
 * throws takes its own card down and leaves the reader with a dashboard that
 * is silent about the very thing it exists to say.
 */
#[CoversClass(CacheWidget::class)]
#[CoversClass(ResourcesWidget::class)]
#[CoversClass(UptimeWidget::class)]
#[CoversClass(Status::class)]
final class HealthWidgetTest extends TestCase
{
    public function test_a_reachable_store_reports_what_it_reached(): void
    {
        $card = $this->cache(new ArrayStore, CacheConfig::ARRAY)->present();

        $this->assertSame(Status::Warning, $card['status']);
        $this->assertSame('per-worker store', $card['caption']);
        $this->assertSame(
            [['label' => 'Driver', 'value' => 'array'], ['label' => 'Scope', 'value' => 'this worker only']],
            $card['rows'],
        );
    }

    public function test_an_array_store_is_reachable_and_still_reported_as_a_caution(): void
    {
        // Reachable is not the question a cache card answers: a per-worker
        // store multiplies every budget counted in it by the worker count.
        $this->assertSame(Status::Warning, $this->cache(new ArrayStore, CacheConfig::ARRAY)->present()['status']);
    }

    public function test_a_store_that_accepts_a_value_and_loses_it_is_reported_down(): void
    {
        $card = $this->cache($this->forgetful(), CacheConfig::ARRAY)->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('Not storing', $card['headline']);
    }

    public function test_a_store_that_will_not_open_is_a_card_and_not_an_exception(): void
    {
        $card = $this->cache((new FakeStore)->failAll(), CacheConfig::REDIS)->present();

        $this->assertSame(Status::Down, $card['status']);
        $this->assertSame('No answer', $card['headline']);
        $this->assertSame('is-down', $card['status']->tone());
        $this->assertSame('redis:6379', $card['caption']);
    }

    public function test_every_gauge_carries_a_caption_and_a_share_that_is_a_percentage(): void
    {
        $gauges = (new ResourcesWidget)->present()['gauges'];

        $this->assertCount(ResourcesWidget::GAUGES, $gauges);

        foreach ($gauges as $gauge) {
            $this->assertNotSame('', $gauge['caption']);
            $this->assertThat($gauge['share'], $this->logicalOr(
                $this->isNull(),
                $this->logicalAnd($this->greaterThanOrEqual(0), $this->lessThanOrEqual(100)),
            ));
        }
    }

    public function test_opcache_off_draws_no_bar_rather_than_an_empty_one(): void
    {
        // The suite runs under the CLI, where opcache.enable_cli is off.
        $opcache = $this->gauge('Opcache');

        $this->assertSame('off', $opcache['figure']);
        $this->assertNull($opcache['share']);
        $this->assertSame('is-warning', $opcache['tone']);
    }

    public function test_debug_left_on_is_flagged_on_the_uptime_card(): void
    {
        $this->assertSame('is-warning', $this->uptimeRows(debug: true)['Debug']);
        $this->assertSame('', $this->uptimeRows(debug: false)['Debug']);
    }

    public function test_the_uptime_card_counts_from_the_host_start_and_not_from_now(): void
    {
        $card = $this->uptime(debug: false);

        $this->assertStringStartsWith('since ', $card['caption']);
        $this->assertNotSame('0s', $card['headline']);
    }

    /** @return array<string, string> */
    private function uptimeRows(bool $debug): array
    {
        return array_column($this->uptime($debug)['rows'], 'tone', 'label');
    }

    /** @return array<string, mixed> */
    private function uptime(bool $debug): array
    {
        return (new UptimeWidget($this->environment($debug)))->present();
    }

    /** @return array<string, mixed> */
    private function gauge(string $label): array
    {
        foreach ((new ResourcesWidget)->present()['gauges'] as $gauge) {
            if ($gauge['label'] === $label) {
                return $gauge;
            }
        }

        self::fail("No \"{$label}\" gauge on the resources card.");
    }

    /**
     * Set on the process rather than written to a .env: Environment exports a
     * file's values to the process environment and then lets the process win,
     * so a second reading of a second file would answer with the first's.
     */
    private function environment(bool $debug): Environment
    {
        $_ENV['APP_DEBUG'] = $debug ? 'true' : 'false';
        putenv('APP_DEBUG=' . $_ENV['APP_DEBUG']);

        return new Environment(sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
    }

    private function cache(StoreInterface $store, string $driver): CacheWidget
    {
        return new CacheWidget($store, new CacheConfig(driver: $driver, host: 'redis', port: 6379));
    }

    /** Takes every write and keeps none of it, which no store a test can configure does. */
    private function forgetful(): StoreInterface
    {
        return new class implements StoreInterface {
            public function get(string $key): mixed
            {
                return null;
            }

            public function put(string $key, mixed $value, int $ttl = 0): void {}

            public function forget(string $key): void {}

            public function increment(string $key, int $by = 1, int $ttl = 0): int
            {
                return $by;
            }

            public function ttl(string $key): int
            {
                return 0;
            }

            public function flush(): void {}
        };
    }
}
