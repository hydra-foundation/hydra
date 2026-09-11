# Hydra HTTP

> Read-only mirror. `hydrakit/http` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/http`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

The request lifecycle: a PSR-15 middleware pipeline wrapping a router, typed 
HTTP errors, and the glue that turns a controller's return value into an emitted 
PSR-7 response. Depends only on PSR-7/-15/-17 interfaces; the concrete request, 
response, and factory implementations are the application's to bind.
