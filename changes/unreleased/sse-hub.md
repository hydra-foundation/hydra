---
section: Broadcast
kind: added
---
Listen tokens: `StreamToken::mint($userId, $topics, $ttl)` signs a user, up to 32 topics and an expiry with the app key, and `open()` gives the `StreamGrant` back, or null when the token is forged, malformed or expired. `inspect()` tells expired from forged, so a page with an old token can fetch a new one while a forgery is refused. Nothing is stored: the expiry is the revocation.

`TopicPolicy` says who may listen: `->allow('module.{slug}', fn ($userId, $params) => …)`, where each `{name}` placeholder is one whole segment and the first matching pattern decides. A topic no pattern matches is refused, and a check that throws is a logged refusal.

**The SSE hub**, `bin/console sse:serve`: a long-running process that holds browser event streams so php-fpm never does. It subscribes to every broadcast on Redis and sends each one to the streams whose token grants its topic, as an SSE event named by the topic. It sends a heartbeat every `SSE_HEARTBEAT` seconds, caps streams at `SSE_MAX_CONNECTIONS` with a 503, drops a browser more than 256 KiB behind, closes a stream when its token expires, and after reconnecting to Redis tells every stream `hub.resync`. `HubStatus` and `HubHealthCheck` report whether it is running, and with `ext-pcntl` it shuts down cleanly on SIGTERM, SIGINT or SIGQUIT (the stop signal `php:fpm` images inherit). The package now requires `hydrakit/console`.

### Upgrading

Run `sse:serve` as a service of its own, beside the scheduler, and proxy `/stream` to it with response buffering off. Register the topics your pages listen to on `TopicPolicy`, and mint tokens only for topics it permits.
