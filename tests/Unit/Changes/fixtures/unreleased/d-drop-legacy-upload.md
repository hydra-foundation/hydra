---
section: The admin
kind: removed
---
`Input::upload()` is gone; use `Input::file()`.

### Upgrading

Replace each `Input::upload()` with `Input::file()`.
