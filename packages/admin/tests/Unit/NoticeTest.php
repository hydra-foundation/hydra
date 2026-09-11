<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\Notice;
use PHPUnit\Framework\TestCase;

final class NoticeTest extends TestCase
{
    public function test_a_success_is_a_status_update(): void
    {
        $notice = Notice::success('Saved.');

        $this->assertSame('Saved.', $notice->text);
        $this->assertSame('success', $notice->style());
        $this->assertSame('status', $notice->role());
    }

    public function test_the_admins_own_wording_lives_in_one_place(): void
    {
        // The controller says what happened; what that reads as is decided here.
        $this->assertSame('Created', Notice::created()->text);
        $this->assertSame('Saved', Notice::saved()->text);
        $this->assertSame('Deleted', Notice::deleted()->text);
        $this->assertSame('status', Notice::deleted()->role());
    }

    public function test_a_failure_announces_itself(): void
    {
        $notice = Notice::failure('That row is spoken for.');

        $this->assertSame('That row is spoken for.', $notice->text);
        $this->assertSame('danger', $notice->style());
        $this->assertSame('alert', $notice->role());
    }
}
