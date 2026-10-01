---
section: Broadcast
kind: changed
---
Five errors now say what to do. The SSE hub's health check names
`bin/console sse:serve` and the `sse` service; a refused Redis connection,
from the cache or the hub, names `REDIS_HOST` and `REDIS_PORT`; a missing
template names every directory it was looked for in (below the one they
share, so no absolute path); and an admin widget or link with no key says
what the key is for.
