---
section: The skeleton
kind: fixed
---
nginx follows the php container to its new address when the container is recreated. It used to resolve `php` once at start and keep sending to the old address, so `up -d --build` after a Dockerfile or compose change left every page a 502 until nginx was restarted too. `docker/nginx/default.conf` now asks Docker's DNS again every 10 seconds (`resolver` plus `server php:9000 resolve` in the upstream), and keeps the upstream's keepalive pool.

### Upgrading

Merge the `resolver` line and the `zone` and `resolve` changes to the `php-fpm` upstream into `docker/nginx/default.conf`, then run `docker restart` on the nginx container: a reload keeps the old file. `resolve` needs nginx 1.27.3 or newer, which `nginx:alpine` has.
