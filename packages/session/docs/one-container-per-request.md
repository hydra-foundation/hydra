# One container per request

Hydra builds **one container per request**. This is not an accident of the
reference app — it is a contract the framework's packages rely on, stated here
once so every package can point at it.

## The contract

The composition root (`Bootstrap::application()` in the reference app) runs at
the top of every request under a classic SAPI (`php-fpm`, `mod_php`):
`public/index.php` builds a fresh container, registers the providers, runs the
kernel, and the process serves nothing else with that container. `singleton()`
therefore means **one instance per request**, never one instance across
requests.

## What relies on it

Several capability packages hold per-request state in request-scoped
singletons. That is safe — and simpler than threading state through PSR-7 —
precisely because the container is rebuilt per request:

- **session** — the store (`NativeSessionStore`) holds the request's session
  data in memory between the middleware's `start()` and `save()`. The store
  enforces its lifecycle: any data access outside that window throws
  `LogicException` rather than silently reading stale state or losing a write.
- **auth** — `SessionGuard` caches the resolved user for the request.
- **csrf** — `CsrfGuard` caches the token it minted for the request.

The native session store goes one step further: it writes through PHP's SAPI
(`$_SESSION`, the session cookie via `session_start()`). Its state — and its
`Set-Cookie` header — live outside the PSR-7 request/response pair by design:
PHP's native session machinery owns id generation, strict-mode fixation
defense, regeneration, and GC, and Hydra chooses not to reimplement that
security-sensitive code.

## The boundary (worker runtimes)

Long-lived worker runtimes (RoadRunner, FrankenPHP worker mode, Swoole) reuse
one process — and would reuse one container — across many requests. That is
**unsupported**:

- request-scoped singletons would leak one client's state into another's
  request (the container would have to be rebuilt per request), and
- the native session store writes through the SAPI, which does not exist per
  request in a worker.

This is a deliberate, documented boundary, not a bug. If a worker runtime ever
becomes a goal, the work is: rebuild (or reset) the container per request, and
swap `NativeSessionStore` for a store that carries its id and cookie through
PSR-7 explicitly. Until then, Hydra is honestly a classic-SAPI framework.
