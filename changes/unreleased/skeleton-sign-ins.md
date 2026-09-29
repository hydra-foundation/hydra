---
section: The skeleton
kind: added
---
Every sign-in is a row in a new `sign_ins` table: who, when it began, when and from where it was last seen. Deleting a row signs that browser out on its next request. Sessions that were signed in before the migration are adopted on their next request, so the deploy signs nobody out. Logging out deletes the row. Changing your password in Settings keeps this sign-in and ends the others. A password reset, or an admin setting a password under Users, ends all of that account's sign-ins, beside the API tokens it already revoked. An hourly `PruneSignIns` task deletes sign-ins idle past `session.gc_maxlifetime`, so a browser returning after that is signed out whenever PHP's collector last ran.

### Upgrading

Run the `create_sign_ins_table` migration before deploying the code, since the guard reads the table as soon as the store is bound. Copy `SignInRepository` and `Tasks/PruneSignIns.php`. In `AppServiceProvider`, bind `SignInStoreInterface` to `SignInRepository`, add `TrackSignInMiddleware` to `MIDDLEWARE` right after `AuthenticateBearerMiddleware`, and schedule `PruneSignIns` hourly. In `PasswordResetController` and `UserSource`, call `SignInStoreInterface::revokeAll()` where they call the token store's.
