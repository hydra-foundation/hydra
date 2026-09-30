---
section: The skeleton
kind: added
---
The skeleton's admin is live: every list refetches itself when a row in its module changes, and the dashboard's Totals strip and Newest accounts card follow new users. `UserRepository` publishes `module.users` after every write through it, so a user made by `make:user`, a new password or avatar, and an email changed or verified all reach open Users lists, as the admin's own writes do.

### Upgrading

Pass `BroadcasterInterface` to `UserRepository` as its second argument in `AppServiceProvider`, and copy its `changed()` calls. Add `->liveOn('module.users')` to the `totals` and `newest` cards in `DashboardModule`. A test that registered its own `module.{slug}` rule on `TopicPolicy` now meets the admin's first: sign it in as someone who may open the module instead.
