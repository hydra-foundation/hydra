---
section: Broadcast
kind: changed
---
The SSE hub waits through PHP 8.6's `Io\Poll` (epoll on Linux) where PHP has it, and through `stream_select` below 8.6. `stream_select` cannot watch a descriptor numbered 1024 or higher, and once any socket passed that the hub stopped serving without a word: no events, no heartbeats, nothing logged. The skeleton's prod limits reached it at the default cap. Now:

- **On 8.6** there is no ceiling but the process's open-file limit.
- **Below 8.6** an unset `SSE_MAX_CONNECTIONS` is 896, what `stream_select` can serve. An explicit value over that stops `sse:serve` at start, before it binds the port, with the fix in the message.
- **If select hits the ceiling anyway**, the hub closes the newest stream each round until it can watch the rest, and says so in the log at most once a minute, instead of going quiet.
- `sse:serve`'s start line names the poller and the cap (`listening on 0.0.0.0:8080 (epoll, up to 1000 streams)`), and the hub's status report carries the poller for System Health.

### Upgrading

- **On PHP 8.4 or 8.5 with `SSE_MAX_CONNECTIONS` set above 896** (the skeleton's `.env.example` had `1000`), the hub refuses to start. Remove the line to take the default, set 896 or less, or move the hub to PHP 8.6.
- `SseServeCommand` takes the `HubFactory` and a logger. Code that built the command itself, rather than through `BroadcastServiceProvider`, passes those.
