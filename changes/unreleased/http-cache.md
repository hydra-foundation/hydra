---
section: HTTP
kind: added
---
A page can be kept and revalidated. `HttpCache::public(maxAge: 300)`, `HttpCache::private()` or `HttpCache::noStore()` says how a response may be kept; `->etag(...$parts)` and `->lastModified($at)` say what tells a later request it hasn't changed. The ETag is weak and hashed from the parts you give (a post's slug and modified time, say) plus the app's `Release`, never from the body, which carries a fresh CSP nonce every time; a deploy therefore makes every page new. `Responder::cached($response, $cache)` sets the headers, `Responder::isFresh($request, $cache)` says whether the reader already holds the page (RFC 9110: `If-None-Match` weakly, else `If-Modified-Since`, GET and HEAD only), and `Responder::notModified($cache)` answers 304 without rendering. `Responder` takes the `Release` as a new optional last argument, and the kernel passes one when the app binds `Release`.
