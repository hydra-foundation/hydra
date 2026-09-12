# Hydra Kernel

> Read-only mirror. `hydrakit/kernel` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/kernel`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

The framework's default composition root and HTTP plumbing.
It exists to keep the wiring that is identical across every
Hydra app in **one place**, so a consumer's `AppServiceProvider`
holds only *policy* and not the boilerplate that used to be
copied into each app and drift.
