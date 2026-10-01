---
section: The skeleton
kind: fixed
---
- The Docker stack runs in a project with any name. It mounted the parent
  directory and named `/var/www/html/app` throughout, so a project made with
  `create-project hydrakit/app myapp` served 404s and its scheduler and hub
  never started. The project is now mounted at `/var/www/html/app` whatever
  its directory is called; working on the framework, the `../hydra` mount
  moves to `docker-compose.local.yml` (copy the example).
- `./bin/prod` publishes nginx on `127.0.0.1:APP_PORT` for the host's reverse
  proxy to reach. It published nothing, so the production stack could only be
  reached from inside Docker.
- nginx believes `X-Forwarded-For` from the Docker network's gateway, read at
  start by `docker/nginx/real-ip.sh`, which is where a host proxy arrives
  through the loopback port. `NGINX_TRUSTED_PROXY` is no longer required, only
  an override.
- The prod overlay hands `REDIS_PASSWORD` to Redis and requires it. Setting
  it, as the production checklist says, made the app send AUTH to a Redis with
  no password, which refused every command.

### Upgrading

- **The skeleton:** A production stack now needs `REDIS_PASSWORD` in `.env`;
  `./bin/prod up -d` refuses to start without it, and recreates Redis with it.
  Working on the framework beside `../hydra`, copy
  `docker-compose.local.yml.example` to `docker-compose.local.yml` before
  `./bin/dev up -d`, or the packages' symlinks resolve to nothing inside the
  containers. A set `NGINX_TRUSTED_PROXY` still wins over the gateway.
