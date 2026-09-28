---
section: The admin
kind: added
---
A file control can keep the name its file was uploaded under: `Input::file('avatar')->keepsName()` hands the source an `avatar_name` beside the key, on the same write, and clears it when the file is removed. Downloads are saved under that name, the edit form shows it with a link, `Field::image()->nameFrom('avatar_name')` uses it as the image's `alt`, and the new `Field::file()` shows any stored file as a download link that reads as its name.

### Upgrading

Nothing changes unless you ask for it. To keep names on an existing file control, add a nullable `VARCHAR(255)` column, call `->keepsName()` on the control and `->nameFrom()` on its field, and have the source read and write the column. Files stored before then have no name, so they download under their key, as they do today.
