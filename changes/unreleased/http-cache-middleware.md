---
section: HTTP
kind: added
---
`HttpCacheMiddleware` handles every response's caching on its way out. A response that says nothing gets `Cache-Control: no-store`; a `public` one that sets a cookie, including the native session's, which PHP queues outside the PSR-7 response, is sent as `private`, so a shared cache never keeps someone's session; and a GET or HEAD 200 whose `ETag` or `Last-Modified` matches the request becomes a `304` with no body, so a controller that only calls `Responder::cached()` still saves its readers the download. No 304s while the `Release` is not conditional (development). Place it outside `StartSessionMiddleware` and `ErrorHandlerMiddleware`.
