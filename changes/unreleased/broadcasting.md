---
section: Broadcast
kind: added
---
A new package, `hydrakit/broadcast`, for telling other processes that something changed. `BroadcasterInterface::publish($topic, $event, $data)` names a topic such as `module.users` or `user.7` and an event such as `changed`; `Topic` holds the naming rules, and `Envelope` is the JSON that travels, capped at 64 KiB because a broadcast is a nudge rather than a way to move records.
