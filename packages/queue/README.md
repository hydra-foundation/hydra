# Hydra Queue

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/queue` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/queue`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Work that outlives the request. A `JobInterface` is built by the container
and handed the payload it was pushed with; `QueueInterface::push()` takes the
job's class, a payload of scalars, nulls and arrays, stored as JSON, and an
optional delay in seconds.

`DatabaseQueue` keeps jobs in a `jobs` table and failures in `failed_jobs`.
There is no daemon: `Worker` is a scheduler batch,
`$schedule->drain(Worker::class)->everyMinute()`, that claims up to twenty
jobs at a time. A job that finishes is deleted. One that throws is tried again
after 10 seconds, then 60, and after its third failure moves to `failed_jobs`
with the exception. A claim whose worker died is taken again after fifteen
minutes, and the attempt is counted when the job is claimed, so a job that
kills its worker still runs out of tries.

`queue:failed` lists what ran out of tries, newest first, with the
exception's message. `queue:retry <id>` puts one back on the queue, due now
and with its tries restored; `queue:retry --all` puts back every one.

`Testing\FakeQueue` records pushes for `assertQueued()`, and refuses whatever
the database queue would.
