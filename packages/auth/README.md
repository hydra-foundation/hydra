# Hydra Auth

> Read-only mirror. `hydrakit/auth` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/auth`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Authentication. Identity only. It answers *who is logged in* and provides the
verbs to change that (attempt/login/logout), behind a swappable guard. It does
not do permissions: those are `hydrakit/authorization`. It does not know your
users: that one contract is left for the app to fulfil.
