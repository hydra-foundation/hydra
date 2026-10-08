---
section: The skeleton
kind: changed
---
Two classes take the names the generators give: the listener `PublishQueueChanges` is `PublishQueueChangesListener`, and the source `ScheduledRunsSource` is `ScheduledRunSource`. `AGENTS.md` lists the folders it left out and no longer says every SQL statement is in a repository: admin sources and dashboard widgets write their own.

### Upgrading

An app made from the skeleton that kept these classes renames them as it merges the release: `src/Listeners/PublishQueueChanges.php` and `src/Admin/Sources/ScheduledRunsSource.php`, their tests, and where `AppServiceProvider` and `ScheduledRunsModule` name them.
