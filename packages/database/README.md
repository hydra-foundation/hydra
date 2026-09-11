# Hydra Database

> Read-only mirror. `hydrakit/database` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/database`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

A thin data-access seam over PDO and a raw-SQL migration runner. 
Repositories write their own SQL and pass bound parameters. 
