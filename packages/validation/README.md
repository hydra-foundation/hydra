# Hydra Validation

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/validation` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/validation`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Validates a set of input values against a per-field list of rules. Stateless.
The rules travel with each call, so one `Validator` can be shared and autowired.
Ships **no** `ServiceProvider`; the validator has no dependencies to bind.

Rule keys are dot paths, and `*` walks a list: `items.*.qty` runs its rules
once per entry and keys each error to `items.0.qty`. A rule is an object
implementing `RuleInterface`, handed the value and a `Context` holding the rest
of the input, which is how `Confirmed`, `Same` and the conditional `Required*`
rules see the field they compare against. `Exists` and `Unique` live in
`hydrakit/database`, which is where the connection is.
