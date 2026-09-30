---
section: The admin
kind: added
---
In-app notifications. `Notifications\Notifier::notify($userId, new Notice($title, $body, $url))` keeps a notice for a user in the application's `NotificationStoreInterface` and publishes `user.{id}` with their unread count, so their open pages update without a reload; the title and body never travel. A `Notice` is plain text, and its link is a path in the application or an http(s) address, never a script. `Testing\NotificationStoreContractTestCase` is what an application's store extends, and `Testing\ArrayNotificationStore` keeps notices in memory.
