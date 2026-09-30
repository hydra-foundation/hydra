<?php

declare(strict_types=1);

namespace Hydra\Broadcast\Hub;

use RuntimeException;

/**
 * Where the hub says it is alive. The key expires on its own, so a hub that
 * died without cleaning up reads as not running within the expiry.
 */
interface HubStatus
{
    /** Best effort: a failure is the implementation's to log. */
    public function publish(HubReport $report, int $ttl): void;

    public function clear(): void;

    /**
     * The last report, or null when there is none.
     *
     * @throws RuntimeException when the status cannot be read at all
     */
    public function read(): ?HubReport;
}
