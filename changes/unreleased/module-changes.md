---
section: The admin
kind: added
---
`Hydra\Admin\Live\ModuleChanges` lets you make a table live from a write the admin didn't make, such as a command, a job or a webhook. Call `$changes->publish('invoices', $id)` and the module's open lists refetch. It builds the `module.{slug}` topic, and does nothing when no broadcaster is bound, so a writer can always depend on it. The admin publishes its own writes through it too.
