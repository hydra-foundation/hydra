<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Support;

use Hydra\Admin\Contracts\ModuleActionInterface;

final class RecordingModuleAction implements ModuleActionInterface
{
    public int $runs = 0;

    public function run(): string
    {
        $this->runs++;

        return 'Everything flagged.';
    }
}
