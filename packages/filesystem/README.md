# Hydra Filesystem

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/filesystem` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/filesystem`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

File storage behind one contract. `StorageInterface` stores a stream under a
key it chooses itself, `directory/<32 hex>.<ext>`, with the extension taken
from the type detected in the bytes. Neither the client's filename nor its
extension ever reaches the disk, so an upload named `shell.php` is stored as
whatever it actually is, under a name nobody picked.

Whether a file is public depends on the disk it is stored on, not on a flag
beside it:

- the **private** disk (`storage/uploads`) is never web-served; the
  application streams a file out after deciding who may read it;
- the **public** disk (`storage/public`) is served by the web server through a
  `public/storage` symlink, which `storage:link` creates. Only it implements
  `PublicStorageInterface::url()`, so asking the private disk for a URL fails
  at the type level rather than in production.

`StorageInterface` resolves to the private disk, so the default is the safe
one. A driver of your own proves itself against
`Hydra\Filesystem\Testing\StorageContractTestCase`.
