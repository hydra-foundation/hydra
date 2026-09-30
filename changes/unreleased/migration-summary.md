---
section: Database
kind: added
---
`MigrationRunner::summary()` returns where the schema stands as a `MigrationSummary`: the migrations applied, those on disk still pending, and which ran last and when. It creates nothing, so a health check can call it on a database nobody has migrated yet, and the time is read as unix seconds so the connection's time zone never moves it.
