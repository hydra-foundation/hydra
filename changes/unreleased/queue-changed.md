---
section: Queue
kind: added
---
The queue says when its tables move. `DatabaseQueue` and `Worker` take an optional PSR-14 dispatcher, and dispatch `Hydra\Queue\Events\QueueChanged` naming the tables that moved (`jobs`, `failed`). This happens once per push, once per worker batch that did anything, and once per retry, cancel, forget or flush that changed a row. The queue still knows nothing about the admin: an application maps the event to its modules, for example to make its Jobs list live.
