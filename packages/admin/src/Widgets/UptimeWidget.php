<?php

declare(strict_types=1);

namespace Hydra\Admin\Widgets;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Core\Environment;

/**
 * How long this host has been up, and what it is running.
 *
 * Every instant on the card is the process's own, not the reader's: the row is
 * there to catch a clock or a zone that is not what the deployment thinks it
 * is, and converting it to the reader's preference would hide exactly that.
 */
final class UptimeWidget implements PresenterInterface
{
    /** The init process is as old as the host, and its entry is stamped with its start. */
    private const INIT = '/proc/1';

    public function __construct(private readonly Environment $env) {}

    public function present(): array
    {
        $zone = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('now', $zone);
        $started = $this->startedAt()?->setTimezone($zone);
        $debug = $this->env->bool('APP_DEBUG', false);

        return [
            'status' => Status::Ok,
            'headline' => $started === null
                ? 'Unknown'
                : Readable::duration($now->getTimestamp() - $started->getTimestamp()),
            'caption' => $started === null
                ? 'this host reports no start time'
                : 'since ' . $started->format('j M Y, H:i'),
            'rows' => [
                ['label' => 'Server time', 'value' => $now->format('H:i:s T')],
                ['label' => 'Host', 'value' => (string) gethostname()],
                ['label' => 'PHP', 'value' => PHP_VERSION . ' · ' . PHP_SAPI],
                [
                    'label' => 'Debug',
                    'value' => $debug ? 'on' : 'off',
                    'tone' => $debug ? Status::Warning->tone() : '',
                ],
            ],
            'note' => null,
        ];
    }

    private function startedAt(): ?DateTimeImmutable
    {
        $stamp = @filemtime(self::INIT);

        return $stamp === false ? null : new DateTimeImmutable('@' . $stamp);
    }
}
