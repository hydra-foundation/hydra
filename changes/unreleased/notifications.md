---
section: The admin
kind: added
---
In-app notifications. `Notifications\Notifier::notify($userId, new Notice($title, $body, $url))` keeps a notice for a user in the application's `NotificationStoreInterface` and publishes `user.{id}` with their unread count, so their open pages update without a reload; the title and body never travel. A `Notice` is plain text, and its link is a path in the application or an http(s) address, never a script. `Testing\NotificationStoreContractTestCase` is what an application's store extends, and `Testing\ArrayNotificationStore` keeps notices in memory.

Where an application binds a `NotificationStoreInterface`, the admin serves the bell's four requests, behind its guard and ahead of the modules: the unread badge, the latest ten, reading one (which follows its link, and is a 404 for anyone else's), and reading all. Reading tells the user's other tabs the new count. While the admin is live, `user.{id}` is granted to that user alone. `hydrakit/admin` now requires `hydrakit/auth`, which it already had through `authorization`.

The bell: pass `'bell' => '/admin/notifications'` to `admin/partials/shell`, and it rings at the end of the account row on the rail and on the narrow top bar. It draws itself empty, asks for its badge on load, and loads the latest ten each time its menu opens; while live, the badge alone refetches on `user.{id}`, so an open menu is never replaced. Opening a notice marks it read and follows its link, Mark all read is one press, and Clear read deletes what has been read, so the menu can be emptied. Without the slot there is no bell, and the account row renders as before.

### Upgrading

`NotificationStoreInterface` is new here, and includes `clearRead()` beside the other five methods. The admin's theme contract gains `--on-accent`, the colour of text on the accent (the unread count), which the skeleton's theme already defines; define it if yours does not.
