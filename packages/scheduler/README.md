# Hydra Scheduler

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/scheduler` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/scheduler`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Scheduled work from one cron entry. The application declares what runs and
when on a `Schedule`: `run()` for a `TaskInterface` that does its work once,
`drain()` for a `BatchInterface` that handles a capped batch and reports how
many it did, and is called again until it reports none or its time budget is
spent. Frequencies are `everyMinute()`, `hourly()`, `dailyAt('04:00')`,
`weekly()` or a five-field `cron()` expression, read in the schedule's own zone
rather than the clock's.

One cron entry runs it: `* * * * * php bin/console schedule:run`. Each task
holds a `flock()` lock while it runs, so the kernel releases it however the run
ends and a crash cannot lock a task out. A run still holding its lock is never
started again beside it; once it has held the lock past `warnAfter()` minutes
(an hour by default) every tick logs a warning instead. Due tasks run one after
another in the order declared, so a long `drain()` belongs at the end.

`SchedulerServiceProvider` binds an empty `Schedule` for the application to
fill from its own provider's `boot()`. Times are read in `SCHEDULE_TIMEZONE`,
else `APP_TIMEZONE`, else UTC; locks live wherever the provider is told unless
`SCHEDULE_LOCK_DIR` moves them.
