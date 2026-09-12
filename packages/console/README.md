# Hydra Console

> Read-only mirror. `hydrakit/console` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/console`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

The generic verbs every Hydra app needs, lifted out of the app skeleton so
two projects don't carry two copies. This package ships the *commands*;
the app owns its `bin/console` entrypoint, wires them to its composition root,
and adds its own app-specific generators.
