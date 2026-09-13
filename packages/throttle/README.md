# Hydra Throttle

> Read-only mirror. `hydrakit/throttle` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/throttle`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Request rate limiting as PSR-15 middleware. A `RateLimitPolicy` is a budget
(a name, a count and a window in seconds); `RateLimiter` spends one request
against it and refuses with a `TooManyRequestsException` carrying `Retry-After`
once it is gone. Counting happens in `hydrakit/cache`'s `StoreInterface`, whose
`increment()` opens the window on the first hit and never re-arms it, so a
steady stream cannot hold a budget open past its limit.

`RateLimitMiddleware` is the application-wide budget, configured from
`RATE_LIMIT`, `RATE_LIMIT_WINDOW` and `RATE_LIMIT_ENABLED`. Place it **inside**
the error handler: an unreachable store raises, and the limiter refuses traffic
it cannot meter rather than serving it unmetered, which only renders as a 500 if
something is there to catch it. A tighter budget for one route is a middleware
of its own, named in that route's `#[Route(middleware: [...])]`, holding its own
policy and calling the same `RateLimiter`.

Who the client is comes from `hydrakit/http`'s `ClientIpResolver`, never from
the forwarding header directly: a limiter that reads `X-Forwarded-For` itself is
one a caller resets by prepending a hop.
