---
section: The skeleton
kind: added
---
The skeleton runs the SSE hub as an `sse` compose service (`bin/console sse:serve`, as www-data), and nginx proxies exactly `/stream` to it with buffering and gzip off, resolving the name per request so nginx starts without it. The token in `/stream?token=…` is redacted from nginx's access log, and the php image gains `pcntl` so the hub stops cleanly.

### Upgrading

Copy the `sse` service into `docker-compose.yml`, `docker-compose.dev.yml` and `docker-compose.prod.yml`, add `pcntl` to the `docker-php-ext-install` list in `docker/php/Dockerfile` and rebuild, and copy the `location = /stream` block and the `/stream` line of the `$loggable_uri` map into `docker/nginx/default.conf`. Add `SseServeCommand` to `bin/console`'s lazy list and the SSE block to `.env`. A reverse proxy in front of nginx must not buffer `/stream` either.
