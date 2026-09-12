# Hydra Validation

> Read-only mirror. `hydrakit/validation` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/validation`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Validates a set of input values against a per-field list of rules. Stateless.
The rules travel with each call, so one `Validator` can be shared and autowired.
Ships **no** `ServiceProvider`; the validator has no dependencies to bind.
