---
section: The skeleton
kind: added
---
The skeleton registers broadcasting, with `BROADCAST_DRIVER=redis` in `.env.example`, and the test harness binds `FakeBroadcaster` in its place. Nothing in the skeleton publishes yet.

### Upgrading

Require `hydrakit/broadcast`. In `Bootstrap`, register `BroadcastServiceProvider` after `MailServiceProvider`; in `TestApp`, register it and then `FakeBroadcastServiceProvider`, and add the fake to `TestAppTest`'s doubles. Add the Broadcasting block to `.env` with `BROADCAST_DRIVER=redis`, or `log` to watch publishes in the log.
