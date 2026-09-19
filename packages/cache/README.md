# Hydra Cache

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/cache` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/cache`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

A key/value store with expiry behind one contract, in two implementations:
Redis, shared by every process that connects to it, and an in-memory array for
tests and single-process tooling. The counter methods are why the contract is
narrow: `increment()` adds to a key and opens its expiry window on the first
hit, as one atomic step, and never re-arms that window afterwards. Anything
budgeting per client therefore counts against a window measured from the first
request rather than one a steady stream can hold open indefinitely. `ArrayStore`
is deliberately **not** a fallback for an unreachable Redis: it is per-process,
so behind a worker pool it would give each worker its own counters and multiply
every limit by the pool size.

A store that cannot answer must say so. An unreachable Redis raises, and so does
one that answers with an error: phpredis reports OOM, READONLY, NOAUTH and
WRONGTYPE by returning `false`, and `(int) false` is `0`, which any limiter
reads as "no requests yet". Deploy Redis with `maxmemory-policy noeviction` for
the same reason, since an evictable counter is a budget that memory pressure,
or anyone able to create it, can clear. `REDIS_READ_TIMEOUT` bounds the wait for
a reply; the connect timeout covers only the handshake.
