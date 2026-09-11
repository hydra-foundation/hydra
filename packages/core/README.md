# Hydra Core

> Read-only mirror. `hydrakit/core` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/core`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

The framework-agnostic foundation: the application object, the contracts every
other package depends on, environment loading, and a base service provider.
Core defines interfaces and orchestration. It ships no concrete container,
kernel, or HTTP layer. Those are bound by the application at runtime.
