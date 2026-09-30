---
section: The skeleton
kind: added
---
**System Health** gains two things for after a deploy. A **Migrations** card says "Up to date" with when the schema last moved, or "N pending" in amber with the pending files named and `./hydra migrate:run` to run; it is read-only, since a migration run from a web request is how a deploy becomes an outage. The **Cache** card gets **Flush**, which empties every key under the cache prefix. The rate limiter counts in that cache, so a flush lets everyone it was refusing back in: the card says how many, and their lockout records are ended in the same press, so Rate limits lists them as Ended rather than in force. A per-worker `CACHE_STORE=array` is refused, since there is nothing shared to flush. Each flush is audited.

### Upgrading

Copy `Admin/Widgets/MigrationsWidget.php` and `Admin/Actions/FlushCache.php`. In `SystemHealthModule`, add the `migrations` card after `updates` (`->from(MigrationsWidget::class)`), and add `->action('flush', 'Flush', FlushCache::class, confirm: '…')` to the `cache` card; `Widget::action()` is new in this release. In `AppServiceProvider`, bind `FlushCache` by hand, passing `LockoutStoreInterface` when it is bound: autowiring fills the optional argument with null, and the flush would then leave lockouts listed that nothing enforces.
