<?php

declare(strict_types=1);

namespace Hydra\Admin\Testing;

use DateTimeImmutable;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * What any notification store must do, for an application's own to extend:
 * keep each user's notices apart, hand them back newest first, and never
 * let one user read or mark another's.
 *
 * store() must return the same store for the length of a test.
 */
abstract class NotificationStoreContractTestCase extends TestCase
{
    abstract protected function store(): NotificationStoreInterface;

    abstract protected function user(): int|string;

    abstract protected function otherUser(): int|string;

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    public function test_an_added_notice_comes_back_whole_and_unread(): void
    {
        $id = $this->store()->add($this->user(), new Notice('Paid', 'Invoice #104 was paid.', '/admin/invoices/104', 'invoice.paid'), self::at('2026-09-30 10:00:00'));

        [$stored] = $this->store()->latest($this->user(), 10);

        $this->assertSame($id, $stored->id);
        $this->assertEquals(new Notice('Paid', 'Invoice #104 was paid.', '/admin/invoices/104', 'invoice.paid'), $stored->notice);
        $this->assertSame('2026-09-30 10:00:00', $stored->createdAt->format('Y-m-d H:i:s'));
        $this->assertNull($stored->readAt);
    }

    public function test_a_notice_with_only_a_title_keeps_its_nulls(): void
    {
        $this->store()->add($this->user(), new Notice('Hello'), self::at('2026-09-30 10:00:00'));

        $this->assertEquals(new Notice('Hello'), $this->store()->latest($this->user(), 1)[0]->notice);
    }

    public function test_latest_is_newest_first_and_limited(): void
    {
        foreach (['one' => '10:00', 'two' => '11:00', 'three' => '12:00'] as $title => $time) {
            $this->store()->add($this->user(), new Notice($title), self::at("2026-09-30 {$time}:00"));
        }

        $this->assertSame(['three', 'two', 'one'], array_map(static fn ($n) => $n->notice->title, $this->store()->latest($this->user(), 10)));
        $this->assertSame(['three', 'two'], array_map(static fn ($n) => $n->notice->title, $this->store()->latest($this->user(), 2)));
    }

    public function test_notices_added_in_the_same_second_keep_the_order_they_were_added(): void
    {
        $this->store()->add($this->user(), new Notice('first'), self::at('2026-09-30 10:00:00'));
        $this->store()->add($this->user(), new Notice('second'), self::at('2026-09-30 10:00:00'));

        $this->assertSame(['second', 'first'], array_map(static fn ($n) => $n->notice->title, $this->store()->latest($this->user(), 10)));
    }

    public function test_each_user_sees_only_their_own(): void
    {
        $this->store()->add($this->user(), new Notice('mine'), self::at('2026-09-30 10:00:00'));
        $this->store()->add($this->otherUser(), new Notice('theirs'), self::at('2026-09-30 10:00:00'));

        $this->assertSame(['mine'], array_map(static fn ($n) => $n->notice->title, $this->store()->latest($this->user(), 10)));
        $this->assertSame(1, $this->store()->unreadCount($this->user()));
        $this->assertSame(1, $this->store()->unreadCount($this->otherUser()));
    }

    public function test_reading_one_marks_it_and_lowers_the_count(): void
    {
        $id = $this->store()->add($this->user(), new Notice('a', url: '/admin'), self::at('2026-09-30 10:00:00'));
        $this->store()->add($this->user(), new Notice('b'), self::at('2026-09-30 10:01:00'));

        $read = $this->store()->markRead($this->user(), $id, self::at('2026-09-30 11:00:00'));

        $this->assertNotNull($read);
        $this->assertSame($id, $read->id);
        $this->assertSame('/admin', $read->notice->url);
        $this->assertSame('2026-09-30 11:00:00', $read->readAt?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->store()->unreadCount($this->user()));
        $this->assertNotNull($this->store()->latest($this->user(), 10)[1]->readAt);
    }

    public function test_reading_one_twice_keeps_the_first_time(): void
    {
        $id = $this->store()->add($this->user(), new Notice('a'), self::at('2026-09-30 10:00:00'));
        $this->store()->markRead($this->user(), $id, self::at('2026-09-30 11:00:00'));

        $again = $this->store()->markRead($this->user(), $id, self::at('2026-09-30 12:00:00'));

        $this->assertSame('2026-09-30 11:00:00', $again?->readAt?->format('Y-m-d H:i:s'));
    }

    public function test_another_users_notice_cannot_be_read(): void
    {
        $theirs = $this->store()->add($this->otherUser(), new Notice('theirs'), self::at('2026-09-30 10:00:00'));

        $this->assertNull($this->store()->markRead($this->user(), $theirs, self::at('2026-09-30 11:00:00')));
        $this->assertSame(1, $this->store()->unreadCount($this->otherUser()));
    }

    public function test_an_unknown_id_is_null(): void
    {
        $this->assertNull($this->store()->markRead($this->user(), '999999', self::at('2026-09-30 11:00:00')));
        $this->assertNull($this->store()->markRead($this->user(), 'not-an-id', self::at('2026-09-30 11:00:00')));
    }

    public function test_reading_all_marks_only_this_users_unread_ones(): void
    {
        $this->store()->add($this->user(), new Notice('a'), self::at('2026-09-30 10:00:00'));
        $read = $this->store()->add($this->user(), new Notice('b'), self::at('2026-09-30 10:01:00'));
        $this->store()->add($this->user(), new Notice('c'), self::at('2026-09-30 10:02:00'));
        $this->store()->add($this->otherUser(), new Notice('theirs'), self::at('2026-09-30 10:00:00'));
        $this->store()->markRead($this->user(), $read, self::at('2026-09-30 10:30:00'));

        $this->assertSame(2, $this->store()->markAllRead($this->user(), self::at('2026-09-30 11:00:00')));
        $this->assertSame(0, $this->store()->unreadCount($this->user()));
        $this->assertSame(1, $this->store()->unreadCount($this->otherUser()));
        $this->assertSame(0, $this->store()->markAllRead($this->user(), self::at('2026-09-30 12:00:00')));
    }

    public function test_a_user_with_nothing_has_nothing(): void
    {
        $this->assertSame([], $this->store()->latest($this->user(), 10));
        $this->assertSame(0, $this->store()->unreadCount($this->user()));
    }
}
