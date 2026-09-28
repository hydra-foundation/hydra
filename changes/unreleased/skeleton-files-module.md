---
section: The skeleton
kind: added
---
Administration → Files lists every stored file on both disks, with a preview, the kept name, type, size, disk, modified time and status, and an Orphans link. A file's screen shows what uses it, linked to the row. Deleting a file that something still uses is refused. Delete orphans clears the files nothing has used for a day. The dashboard gains a Files card.

### Upgrading

Copy `FilesModule`, `views/admin/widgets/files.php`, the `FilesModule` entry in `AppServiceProvider::MODULES`, and the Files widget in `DashboardModule`. If your app stores files outside its admin modules, list a `FileHolderInterface` for them in the `AdminServiceProvider`'s `fileHolders`, or those files show as orphans. `LogSource` now formats its note with `Readable::bytes()`.
