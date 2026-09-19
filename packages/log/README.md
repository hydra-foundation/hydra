# Hydra Log

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/log` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/log`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

A minimal PSR-3 logger that writes one plain-text line per record to a writable
stream. No handlers, no processors, no formatters config. Hydra's logging is a
single deliberate class, the data-layer-style "ship the verb" of logging.
