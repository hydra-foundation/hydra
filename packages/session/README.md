# Hydra Session

> Read-only mirror. `hydrakit/session` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/session`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

A request-scoped key/value store that persists across requests for one client,
backed by native `$_SESSION` behind two deliberately split interfaces. The data
surface goes to controllers; the lifecycle surface is held only by the
framework's session middleware.
