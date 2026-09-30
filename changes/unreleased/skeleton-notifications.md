---
section: The skeleton
kind: added
---
The skeleton rings the bell. A `notifications` table and `NotificationRepository` (held to the admin's store contract) keep each user's notices, the admin layout passes the shell its bell, and read notices are pruned after ninety days at 03:30. The account's security notices, two-factor turned on or off, a recovery code used, and an email address changed, still mail and now also appear on the owner's bell. `./hydra notify:demo <username>` sends a sample.

### Upgrading

Run `./hydra migrate:run` for the `notifications` table, and add it to `TestSchema`. Copy `NotificationRepository` and bind it by hand in `AppServiceProvider`, resolving `NotificationStoreInterface` to the same instance; schedule `PruneNotifications` daily at 03:30. In `views/layouts/admin.php`, pass `'bell' => '/admin/notifications'` to the shell. The notice jobs take a `Notifier`; `NotifyDemoCommand` is optional.
