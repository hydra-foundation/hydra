---
section: Broadcast
kind: added
---
A new package, `hydrakit/broadcast`, for telling other processes that something changed. `BroadcasterInterface::publish($topic, $event, $data)` names a topic such as `module.users` or `user.7` and an event such as `changed`; `Topic` holds the naming rules, and `Envelope` is the JSON that travels, capped at 64 KiB because a broadcast is a nudge rather than a way to move records.

`BROADCAST_DRIVER` picks `redis`, `log` or `null` (the default). The redis driver publishes on `{REDIS_PREFIX}broadcast.{topic}` over the cache's `REDIS_*` settings, whatever `CACHE_STORE` says, and is best effort: a Redis that is down is logged as a warning and the request that published carries on. Every driver refuses a bad topic, event name or payload, the null one included, so development catches what production would.

### Upgrading

Register `Hydra\Broadcast\BroadcastServiceProvider` and set `BROADCAST_DRIVER=redis` to publish; nothing publishes until something calls `publish()`.
