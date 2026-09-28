---
section: The filesystem
kind: added
---
`StorageInterface::list()` walks a disk, or one directory on it, and yields a `StoredFile` for each file: its key, size, detected MIME type and modified time. It is lazy, so memory stays flat on a disk of any size. It returns only keys the disk's other methods accept, so a `.gitignore` or a file copied in by hand under an odd name is skipped, and it never follows a symlink.

### Upgrading

A storage driver of your own must now implement `list()`. `StorageContractTestCase` has the cases it owes. The bundled disks need nothing.
