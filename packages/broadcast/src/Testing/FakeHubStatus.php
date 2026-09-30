<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Testing;

use Hydra\Broadcast\Hub\HubReport;
use Hydra\Broadcast\Hub\HubStatus;

/** A status held in memory, for a test that plays the hub or reads what it said. */
final class FakeHubStatus implements HubStatus
{
    public ?HubReport $report = null;
    public ?int $ttl = null;
    public int $writes = 0;

    public function publish(HubReport $report, int $ttl): void
    {
        $this->report = $report;
        $this->ttl = $ttl;
        $this->writes++;
    }

    public function clear(): void
    {
        $this->report = null;
    }

    public function read(): ?HubReport
    {
        return $this->report;
    }
}
