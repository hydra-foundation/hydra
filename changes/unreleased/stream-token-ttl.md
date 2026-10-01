---
section: Broadcast
kind: changed
---
A listen token lasts 15 minutes by default, down from an hour. Expiry is
the only way a token is revoked, so this is the longest an open stream
outlives a sign-out, a revoked sign-in or a lost ability.

### Upgrading

To keep the hour, set `STREAM_TOKEN_TTL=3600`. The skeleton's `app.js`
refetches a page's live lists when its stream reopens, so a change published
while a token was being renewed is not missed; a stream client of your own
should do the same.
