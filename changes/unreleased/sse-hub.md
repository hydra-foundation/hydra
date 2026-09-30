---
section: Broadcast
kind: added
---
Listen tokens: `StreamToken::mint($userId, $topics, $ttl)` signs a user, up to 32 topics and an expiry with the app key, and `open()` gives the `StreamGrant` back, or null when the token is forged, malformed or expired. `inspect()` tells expired from forged, so a page with an old token can fetch a new one while a forgery is refused. Nothing is stored: the expiry is the revocation.

`TopicPolicy` says who may listen: `->allow('module.{slug}', fn ($userId, $params) => …)`, where each `{name}` placeholder is one whole segment and the first matching pattern decides. A topic no pattern matches is refused, and a check that throws is a logged refusal.
