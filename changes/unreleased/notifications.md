---
section: The admin
kind: added
---
In-app notifications. `Notifications\Notifier::notify($userId, new Notice($title, $body, $url))` keeps a notice for a user in the application's `NotificationStoreInterface` and publishes `user.{id}` with their unread count, so their open pages update without a reload; the title and body never travel. A `Notice` is plain text, and its link is a path in the application or an http(s) address, never a script. `Testing\NotificationStoreContractTestCase` is what an application's store extends, and `Testing\ArrayNotificationStore` keeps notices in memory.

Where an application binds a `NotificationStoreInterface`, the admin serves the bell's four requests, behind its guard and ahead of the modules: the unread badge, the latest ten, reading one (which follows its link, and is a 404 for anyone else's), and reading all. Reading tells the user's other tabs the new count. While the admin is live, `user.{id}` is granted to that user alone. `hydrakit/admin` now requires `hydrakit/auth`, which it already had through `authorization`.
