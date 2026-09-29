---
section: The skeleton
kind: added
---
Administration → Access gains a Sessions tab, and its strip reads `API tokens | Sessions`. Sessions lists every live sign-in: owner, IP address, browser, when it began and when it was last seen, searchable by username, email or address, with your own marked "This is you". Revoking a sign-in signs that browser out on its next request; your own can't be revoked from here, since Sign out does that. **Sign out everywhere**, on a user's row under Users and on each of their sign-ins, ends every sign-in and API token they have at once, and says how many. On your own account it keeps the sign-in you're using. Both are audited.

### Upgrading

Copy `SessionsModule`, `SessionSource`, `Auth/SignInWindow.php`, `Auth/SignOutEverywhere.php` and the two `SignOut…Everywhere` actions. Register `SessionsModule` after `AccessModule`, add `->tabLabel('API tokens')` to `AccessModule` and the `sign-out-everywhere` row action to `UsersModule`, and add `'sessions' => ['owner', 'ip']` to `AuditAdminEventsListener::AUDITED`. `PruneSignIns` now takes a `SignInWindow` in place of a clock.
