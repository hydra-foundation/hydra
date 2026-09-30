# Hydra Broadcast

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/broadcast` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/broadcast`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Tell other processes that something changed.
`BroadcasterInterface::publish($topic, $event, $data)` names a topic, such as
`module.users` or `user.7`, and an event, such as `changed`.

## Topics and event names

A topic is dot-separated segments of `a-z`, `0-9`, `_` and `-`, at most 128
characters. An event name is `a-z`, `0-9`, `_`, `.` and `-`, at most 64
characters. `Topic::assertValid()` and `Topic::assertValidEvent()` throw an
`InvalidArgumentException` naming the offender.

## The envelope

What travels is an `Envelope`: the topic, the event, the data, and when it was
published in unix milliseconds, as one JSON object. The encoded envelope is at
most 64 KiB (`Envelope::MAX_BYTES`). A broadcast says what changed, not the
records: anyone allowed to listen on the topic reads the data.

## Publishing

```php
use Hydra\Broadcast\Contracts\BroadcasterInterface;

public function __construct(private BroadcasterInterface $broadcaster) {}

// After the write has happened (and after its transaction has committed):
$this->broadcaster->publish('module.users', 'changed', ['id' => $id]);
```

Anything can publish: a controller, a listener, a queued job.

## Drivers

`BroadcastServiceProvider` binds `BroadcasterInterface`, choosing the driver
from `BROADCAST_DRIVER`:

| Driver | What a publish does |
|---|---|
| `redis` | `PUBLISH {REDIS_PREFIX}broadcast.{topic} <envelope>` |
| `log` | One info line, `Broadcast {event} on {topic}`, with the data as context |
| `null` (the default) | Nothing |

The redis driver uses the cache's `REDIS_*` settings and its
`RedisConnection`, whatever `CACHE_STORE` says. The connection opens on the
first publish and is kept for the process, so a request that never publishes
never touches Redis.

**Publishing is best effort.** Redis pub/sub has no delivery guarantee: a
message nobody is subscribed to is gone. So a Redis that is down, or a
connection that breaks, is logged as a warning (`Could not broadcast {event}
on {topic}: …`) and the call returns. The write that published still
succeeds, and the next publish opens a fresh connection. A bad topic, event
name or payload is the caller's mistake, and throws under every driver, the
null one included.

## Testing

Register `Testing\FakeBroadcastServiceProvider` after the real provider. It
binds a `FakeBroadcaster`, resolvable as itself:

```php
$fake = $app->get(FakeBroadcaster::class);

$fake->assertPublished('module.users', 'changed');
$fake->assertPublished('user.7', 'notification', fn (Envelope $e) => $e->data['count'] === 3, times: 1);
$fake->assertNotPublished('module.files');
$fake->assertNothingPublished();
```

The fake validates like the real drivers, so a test can't pass on a topic
that Redis would refuse.
