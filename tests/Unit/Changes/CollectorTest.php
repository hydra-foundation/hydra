<?php

declare(strict_types=1);

namespace Hydra\Tests\Unit\Changes;

use Hydra\Tools\Changes\Collector;
use Hydra\Tools\Changes\Fragment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Collector::class)]
final class CollectorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    public function test_fragments_become_the_body_of_a_release(): void
    {
        $fragments = array_map(Fragment::fromFile(...), glob(self::FIXTURES . '/unreleased/*.md') ?: []);

        $this->assertStringEqualsFile(self::FIXTURES . '/collected.md', (new Collector($fragments))->body());
    }

    public function test_sections_keep_the_order_they_were_first_seen_in(): void
    {
        $collector = new Collector([
            new Fragment('b', 'Validation', 'fixed', 'B.'),
            new Fragment('a', 'The admin', 'added', 'A.'),
            new Fragment('c', 'Validation', 'added', 'C.'),
        ]);

        $this->assertSame(['Validation', 'The admin'], array_keys($collector->sections()));
        $this->assertSame(['c', 'b'], array_map(fn (Fragment $f) => $f->slug, $collector->sections()['Validation']));
    }

    public function test_notes_of_one_kind_keep_their_order(): void
    {
        $collector = new Collector([
            new Fragment('z', 'A', 'added', 'Z.'),
            new Fragment('y', 'A', 'added', 'Y.'),
        ]);

        $this->assertSame("## A\n\n- Z.\n- Y.\n", $collector->body());
    }

    public function test_no_upgrading_means_no_upgrading_section(): void
    {
        $this->assertStringNotContainsString('Upgrading', (new Collector([new Fragment('a', 'A', 'added', 'A.')]))->body());
    }

    public function test_a_security_note_makes_it_a_security_release(): void
    {
        $this->assertFalse((new Collector([new Fragment('a', 'A', 'removed', 'A.')]))->security());
        $this->assertTrue((new Collector([
            new Fragment('a', 'A', 'added', 'A.'),
            new Fragment('b', 'B', 'security', 'B.'),
        ]))->security());
    }

    public function test_nothing_collected_is_an_empty_body(): void
    {
        $collector = new Collector([]);

        $this->assertSame('', $collector->body());
        $this->assertSame([], $collector->sections());
    }
}
