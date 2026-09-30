---
section: Broadcast
kind: added
---
Listen tokens: `StreamToken::mint($userId, $topics, $ttl)` signs a user, up to 32 topics and an expiry with the app key, and `open()` gives the `StreamGrant` back, or null when the token is forged, malformed or expired. `inspect()` tells expired from forged, so a page with an old token can fetch a new one while a forgery is refused. Nothing is stored: the expiry is the revocation.
