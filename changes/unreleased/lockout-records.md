---
section: Rate limiting
kind: added
---
Lockouts can be recorded, listed and released. A `LockoutStoreInterface` holds one `Lockout` per client a policy is refusing: the policy, who it counted by, since when, until when, and the budget it broke. `ArrayLockoutStore` and `LockoutStoreContractTestCase` come with it. `RateLimitPolicy::key($name, $identity)` names a counter without the policy that counts it.
