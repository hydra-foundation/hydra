---
section: The skeleton
kind: added
---
Jobs, Failed jobs, Mail, Sessions and Audit are now live. Each of their writers publishes through `ModuleChanges`, so an open list refetches when a row arrives or leaves. `PublishQueueChanges` maps the queue's `QueueChanged` to the two queue modules. `SentMailRepository`, `SignInRepository` and `AuditRepository` publish after each write that moved a row. Sessions never publishes on the per-request touch, and never sends a sign-in's id. `UserRepository` publishes through `ModuleChanges` too, with its behaviour unchanged. Activity, Logs and Rate limits stay snapshots, because their rows are written on every request.

### Upgrading

Pass `events: $container->get(EventDispatcherInterface::class)` to `DatabaseQueue` and `Worker` in `AppServiceProvider`. Then copy `PublishQueueChanges` and register it for `QueueChanged`. Give each repository that should publish a `ModuleChanges $changes = new ModuleChanges` constructor argument, and bind the repository by hand with `$container->get(ModuleChanges::class)`: PHP-DI skips optional constructor parameters, so an autowired repository publishes nothing. `UserRepository` now takes `ModuleChanges` in place of `?BroadcasterInterface`.
