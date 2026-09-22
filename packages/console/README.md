# Hydra Console

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/console` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/console`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Two things: what a command *is*, and the generic verbs every Hydra app needs.

A command implements `CommandInterface` — its arguments and options declared as
data, its name on an `#[AsCommand]` attribute so a console can list it without
building it, and a body written against a narrow `OutputInterface`. Nothing
here knows which library will run it; `hydrakit/symfony-console` is the adapter
that does, and it is the only package that names one. `Testing/FakeOutput`
records what a command said and answers what it asked, so a command is an
ordinary object in a test rather than something to be driven through a console.

The verbs — `key:generate`, `migrate:*`, `route:cache*`, `make:migration` —
are lifted out of the app skeleton so two projects don't carry two copies. The
app owns its `bin/console` entrypoint, wires them to its composition root, and
adds its own app-specific generators.
