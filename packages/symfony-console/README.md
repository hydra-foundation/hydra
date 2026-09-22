# Hydra Symfony Console

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/symfony-console` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/symfony-console`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

`hydrakit/console` is deliberately free of any console vendor. A command there
implements `CommandInterface`, declares its arguments and options as data, and
writes against a ten-method `OutputInterface`. This package is the default
adapter that runs those commands on
[symfony/console](https://github.com/symfony/console), and the only package in
the framework that names it.

The reason is not that the library might be swapped, because it will not be.
It is that `Command` was extended directly by every command in the framework
*and* in every application built on it, so a breaking change upstream reached
those applications through Hydra without Hydra's own version number saying
anything about it. One class extends Symfony's now: `SymfonyCommand`, which
translates a command's declarations into an `InputDefinition` and its
`ExitCode` into a process status. `SymfonyInput` and `SymfonyOutput` are one
call deep each, because the seam exists to keep the framework off a
third-party base class rather than to replace the drawing — the drawing is the
part worth keeping.

`ConsoleApplication` is what an app's `bin/console` builds, so the entrypoint
names no third-party class either. Commands registered with `addLazy()` are
keyed by class rather than by name, since the name is already on each class's
`#[AsCommand]`; running one command does not construct the others, which is
what keeps `migrate:run` from opening the database connection a generator
wants.
