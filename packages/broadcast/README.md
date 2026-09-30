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

## Listening: the SSE hub

php-fpm can't hold a stream: each open tab would keep a worker busy until
it closes. So browsers listen to **the hub**, a long-running process of its
own:

```
php bin/console sse:serve
```

It runs one non-blocking loop over its listening socket, every browser, and
a Redis pattern subscription on `{REDIS_PREFIX}broadcast.*`. Each broadcast
goes to every open stream whose token grants its topic, as an SSE event
named by the topic:

```
event: module.users
data: {"event":"changed","data":{"id":7}}
```

- **Heartbeat:** `: ping` after `SSE_HEARTBEAT` quiet seconds (15).
- **Connection cap:** past `SSE_MAX_CONNECTIONS` (1000), a browser gets
  `503` with `Retry-After`.
- **Slow browsers:** one that falls more than 256 KiB behind is dropped. A
  request head has to arrive within 5 seconds and 8 KiB.
- **Redis:** a lost subscription is retried after 1, 2, 4, … up to 30
  seconds. Once it's back, every stream gets `event: hub.resync`, since
  events may have been missed.
- **Stopping:** with `ext-pcntl`, SIGTERM, SIGINT and SIGQUIT close every
  stream and clear the status. SIGQUIT is there because an image built on
  `php:fpm` inherits it as its stop signal.

It listens on `SSE_LISTEN` (`0.0.0.0:8080`). Put it behind the web server
at `/stream`, with response buffering off.

### Listen tokens

A browser opens `/stream?token=…`. `StreamToken::mint($userId, $topics,
$ttl)` signs the user, up to 32 topics and an expiry with the app key. The
hub checks the token and never reads a session:

| Token | The hub answers |
|---|---|
| valid | `200` and the stream |
| expired | `204`, which stops EventSource: fetch a fresh token |
| forged or malformed | `403` |

A stream is closed when its token expires. Nothing is stored and nothing
revokes a token, so a sign-out reaches an open tab within
`STREAM_TOKEN_TTL` (3600 seconds). Tokens never reach a log line: one in a
log is a stream anyone can open.

### Who may listen

Mint only what `TopicPolicy` permits. It's shared, and it refuses any
topic that no pattern matches:

```php
$container->get(TopicPolicy::class)
    ->allow('module.{slug}', fn (int|string $userId, array $params): bool => $gate->allows($userId, $params['slug']))
    ->allow('user.{id}', fn (int|string $userId, array $params): bool => (string) $userId === $params['id']);

$policy->permits($userId, 'module.users'); // bool
```

Each `{name}` placeholder is one whole segment, and the first matching
pattern decides. A check that throws is a refusal, and it's logged.

### Health

The hub writes a `HubReport` (pid, start time, open streams, and whether
Redis is subscribed) to `{REDIS_PREFIX}sse:hub` every 10 seconds, with a
30-second expiry. `HubStatus::read()` returns it, or null when no hub is
running. `HubHealthCheck` (named `sse`) fails when there's no report, or
when the hub has lost Redis.
