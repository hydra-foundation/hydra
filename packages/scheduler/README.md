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

Work in progress: the runner and the `schedule:run` command are not here yet.
