---
section: The skeleton
kind: added
---
The skeleton runs the SSE hub as an `sse` compose service (`bin/console sse:serve`, as www-data), and nginx proxies exactly `/stream` to it with buffering and gzip off, resolving the name per request so nginx starts without it. The token in `/stream?token=…` is redacted from nginx's access log, and the php image gains `pcntl` so the hub stops cleanly.

Pages go live with two attributes: `data-stream="module.users"` on an element makes the stream client in `app.js` dispatch `sse:module.users` on it for every broadcast on that topic, so `hx-trigger="sse:module.users"` refetches what it shows. The client gets its token from the new `GET /stream/token?topics=…`, which grants only what `TopicPolicy` permits (60 a minute), and fetches a fresh one whenever the hub closes the stream. The signed-in home page shows a box that refreshes on `./hydra broadcast:demo`, and System Health gains a **Live updates** card. A guest request that asks for JSON and not HTML now gets its 401 instead of a redirect to /login.

### Upgrading

Copy the `sse` service (with its `stop_signal: SIGTERM`) into `docker-compose.yml`, `docker-compose.dev.yml` and `docker-compose.prod.yml`, add `pcntl` to the `docker-php-ext-install` list in `docker/php/Dockerfile` and rebuild, and copy the `location = /stream` block and the `/stream` line of the `$loggable_uri` map into `docker/nginx/default.conf`. Add `SseServeCommand` to `bin/console`'s lazy list and the SSE block to `.env`. Copy `StreamTokenController` with `StreamTokenThrottleMiddleware` (add it to `CONTROLLERS`), register your topics on `TopicPolicy` in `AppServiceProvider::boot()`, and append the live-updates block to `public/js/app.js`. For the card, copy `SseHubWidget` into `SystemHealthModule` after `migrations`, and bind `FakeHubStatus` as `HubStatus` in `TestApp`. The demo (`HomeController::demo`, `partials/stream-demo`, `BroadcastDemoCommand`) is optional. `RedirectUnauthenticatedMiddleware` gains the JSON check. A reverse proxy in front of nginx must not buffer `/stream` either.
