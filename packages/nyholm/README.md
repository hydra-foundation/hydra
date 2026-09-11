# Hydra Nyholm

> Read-only mirror. `hydrakit/nyholm` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/nyholm`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

`hydrakit/http` is deliberately free of any PSR-7 vendor. It depends only on the 
PSR interfaces and defines a `ServerRequestProviderInterface` seam for building 
the incoming request from the environment. This package is the default adapter 
that fills that seam with [nyholm/psr7](https://github.com/Nyholm/psr7).
