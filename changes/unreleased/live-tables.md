---
section: The admin
kind: added
---
The admin publishes its own writes. `Live\PublishAdminEvents` turns `RowCreated`, `RowUpdated`, `RowDeleted` and `ActionTaken` into a `module.{slug}` broadcast (event `changed`, with the action and the row's id, never its values); an export changes nothing and is not published. `hydrakit/broadcast` is suggested, not required. `AdminServiceProvider` wires it on its own when a `BroadcasterInterface` is bound: the listener goes on the `ListenerProvider`, and `module.{slug}` goes on the `TopicPolicy`, granted to exactly who may open the module (a known slug whose ability the gate allows, or which has none). Without a broadcaster, nothing is wired and `Live\LiveAdmin` says so.

List screens are live then too. The body carries one hidden element that listens on `module.{slug}` and, after half a second of quiet (`hx-trigger="sse:module.{slug} delay:500ms"`), refetches the list exactly as shown (its filter, sort and page) into `#admin-body`, pushing no URL. The refetch is marked `_live=1`, and the tallies beside the filter links are counted again for it rather than served from the browser's cache. `ListViewModel` gains `liveTopic` and `liveUrl()`, and `AdminController` an optional `LiveAdmin`; not live, a list renders exactly as before.
