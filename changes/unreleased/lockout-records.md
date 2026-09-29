---
section: Rate limiting
kind: added
---
Lockouts can be recorded, listed and released. A `LockoutStoreInterface` holds one `Lockout` per client a policy is refusing: the policy, who it counted by, since when, until when, and the budget it broke. `ArrayLockoutStore` and `LockoutStoreContractTestCase` come with it. `RateLimitPolicy::key($name, $identity)` names a counter without the policy that counts it.

Bind one and `RateLimiter` records a lockout when a request first goes past a budget, and only then: the requests before it and the refusals after it write nothing. `RateLimiter::release($policy, $identity)` forgets the counter and the record, so that client's next request opens a fresh window. A lockout that can't be recorded is logged and passed over; the refusal stands either way. With no store bound, the limiter is exactly what it was.
