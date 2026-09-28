## Upgrading

- **The admin:** Replace each `Input::upload()` with `Input::file()`.
- **The admin:** Register `FilesystemServiceProvider` before declaring a file control:

  ```php
  $app->register(FilesystemServiceProvider::class);
  ```

## The admin

- **Removed.** `Input::upload()` is gone; use `Input::file()`.
- `Field::image()` takes a `fallback` icon.
- `Input::file()` declares a file control, and a form holding one posts multipart.
- A thumbnail no longer stretches a portrait image.

## The filesystem

- **Security.** A key holding `..` is refused, so a crafted name cannot escape the disk's root.
  Reported privately; thank you.
- A local disk stores uploads under `storage/`.
