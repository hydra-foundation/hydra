---
section: Authentication
kind: added
---
Sign-ins can be recorded, listed and revoked. Bind a `SignInStoreInterface` and `SessionGuard` writes a record at every login and checks it on every request after it, so deleting the record signs that browser out on its next request, with its session cleared as a changed password clears it. A session signed in before the store was bound is adopted, not signed out. Logging out deletes the record, and `refresh()` after a password change deletes every other sign-in the account had. `SessionGuard::signIn()` returns the current record. `ArraySignInStore` and `SignInStoreContractTestCase` come with it. With no store bound, the guard is exactly what it was.

`TrackSignInMiddleware` records when and from where each sign-in was last seen, through `SessionGuard::seen()`: the client address (through your trusted proxies) and the user agent, at most once a minute unless either changes. It passes over bearer requests, and logs and swallows a failed write rather than failing the request.
