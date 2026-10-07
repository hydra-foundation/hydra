---
section: HTTP
kind: changed
---
The native session no longer stamps `Cache-Control: no-store, no-cache, must-revalidate`, `Expires: Thu, 19 Nov 1981` and `Pragma: no-cache` on every response. PHP's session cache limiter did that behind the app's back, so a controller that set its own `Cache-Control` sent two that disagreed. What a response may be kept for is now `HttpCacheMiddleware`'s to say, and it says `no-store` for anything that says nothing, as before.

### Upgrading

Add `Hydra\Http\HttpCacheMiddleware` to your middleware stack, outside `StartSessionMiddleware` and `ErrorHandlerMiddleware` (the skeleton puts it just after `RequestLoggingMiddleware`). Without it your responses carry no `Cache-Control` at all, where they used to carry PHP's `no-store`.
