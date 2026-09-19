# Hydra CSRF

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/csrf` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/csrf`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Synchronizer-token CSRF protection: one secret token per session, compared in
constant time against whatever an unsafe request submits. State lives entirely
in the session, so the guard is stateless and the package ships **no**
`ServiceProvider`. The guard autowires from the session and `Signer` bindings.
