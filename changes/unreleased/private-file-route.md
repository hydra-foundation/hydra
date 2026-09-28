---
section: The admin
kind: changed
---
Private files are served at `GET /admin/file?key=…`, singular, to match the route's name `admin.file`. `/admin/files` now belongs to the Files module's list. `FileUrls::PATH` is the one place the path is spelled.

### Upgrading

Links built with `FileUrls`, including every `Field::image()` and `Field::file()`, follow on their own. Code or templates that hard-coded `/admin/files?key=` must use `FileUrls`, or the new path. The old path does not redirect: it is whatever the application puts at `/admin/files`, which in the skeleton is the admin-only Files module.
