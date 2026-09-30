---
section: The admin
kind: added
---
The admin publishes its own writes. `Live\PublishAdminEvents` turns `RowCreated`, `RowUpdated`, `RowDeleted` and `ActionTaken` into a `module.{slug}` broadcast (event `changed`, with the action and the row's id, never its values); an export changes nothing and is not published. `hydrakit/broadcast` is suggested, not required.
