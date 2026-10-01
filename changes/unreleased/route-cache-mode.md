---
section: HTTP
kind: fixed
---
- The route cache is written readable by every user (0644). It was 0600, so
  a `route:cache` run as root in the php container left a file php-fpm, as
  www-data, could not read, and with `ROUTE_CACHE=true` every request failed
  at boot. Run `route:cache` again after upgrading to rewrite it.
