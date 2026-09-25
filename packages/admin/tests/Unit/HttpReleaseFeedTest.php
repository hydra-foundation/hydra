<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Updates\HttpReleaseFeed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpReleaseFeed::class)]
final class HttpReleaseFeedTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'feed');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_a_json_object_is_decoded(): void
    {
        file_put_contents($this->path, '{"latest":"0.9.7"}');

        $this->assertSame(['latest' => '0.9.7'], (new HttpReleaseFeed($this->path))->fetch());
    }

    public function test_anything_but_a_json_object_is_nothing(): void
    {
        foreach (['<html>502</html>', '"0.9.7"', ''] as $body) {
            file_put_contents($this->path, $body);

            $this->assertNull((new HttpReleaseFeed($this->path))->fetch());
        }
    }

    public function test_an_unreadable_feed_is_nothing(): void
    {
        $this->assertNull((new HttpReleaseFeed($this->path . '.missing'))->fetch());
    }
}
