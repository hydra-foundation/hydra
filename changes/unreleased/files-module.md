---
section: The admin
kind: added
---
`Bytes::human()` formats a size as a person reads one (`13.3 KB`, `1.5 MB`). `Files\FileId` turns a qualified key into a row id that fits one URL segment (`private~avatars~ab12.png`) and back, and gives null for an id that names no key.

`Files\FileSource` makes every stored file a row: its name (the kept one, or the key's), type and kind, size, disk, modified time, and status (in use, orphan, or new inside the grace period). Each row also lists what uses the file, linked to that row. It filters, searches and sorts in memory, and its page note totals both disks. Deleting a file that anything still uses is refused, and the refusal names what uses it. References are checked again at the moment of the delete. `FileReferences::isNew()` says whether a file is still inside the grace period.
