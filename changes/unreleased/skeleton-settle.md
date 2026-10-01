---
section: The skeleton
kind: added
---
- `AGENTS.md` gives coding agents the skeleton's commands, where things go,
  one way to do each task (its generator and its wiki page) and the
  boundaries; `CLAUDE.md` imports it. `AgentsGuideTest` checks every command,
  composer script and path it names, so a rename fails the suite.
- `make:job SendInvoice` writes a queued job in `App\Jobs`, and says how to
  push it: the payload is JSON, so it carries ids, not objects.
- `STREAM_TOKEN_TTL=900` in `.env.example`, and `app.js` sends every topic a
  resync when a stream that was lost (an expired token, a dropped
  connection) opens again, so a change published while it reconnected is not
  missed. A swap that only widened the grant does not resync.
- nginx's `/stream` location logs errors at `crit`, so a hub that is down no
  longer writes listen tokens into the error log on every browser retry.
