# Roadmap

Unreleased intent. Each item graduates to a spec (in the hydra-foundation root)
when work starts, and to a changelog entry when it ships.

Last shipped: **0.10.2 — Narrow screens** (the admin fits a phone, and the
storage contract test stops failing at random). Before it, **0.10.1 — Paging**
(`Paging`, `Paginated` and `Responder::paginated()` in `http`, with the admin
rebuilt on them).

## Principles

Every item below has to hold up against these.

1. **Hydra provides the building blocks, and apps build the features.** Hydra
   ships no blog, shop or chat. It makes each one easy to build. The test:
   if two unrelated apps would build it the same way, it belongs in Hydra.
   If not, it belongs in the app.
2. **Human developers and agents alike.** A feature is not done until both a
   person and an agent can find it, follow it and get it right the first time.
   See "Humans and agents" below.
3. **SQL is what you read.** The connection is five methods, deliberately too
   small for a fluent query API. Helpers may make SQL cheaper to write, but
   none of them becomes a second way to express a query.
4. **Nothing is included just because an earlier framework had it.** Echo and
   early Hydra had things that did not earn their place. An item is here for
   what it does for an app built today.

## Humans and agents

This is a gate on every milestone, not a milestone of its own.

- **Recipes.** Every building block ships with a wiki page that shows a real
  task from start to finish ("keep a file's original name", "take a
  payment"). A person follows it, and an agent reads it as a spec.
- **Generators.** `make:migration` exists. Add `make:module`, `make:source`,
  `make:job`, `make:listener` and `make:controller`, so new code starts out
  in the house style instead of from a blank file or a guess.
- **Errors say what to do.** The admin already does this ("Register
  FilesystemServiceProvider ahead of the AdminServiceProvider"). Make it the
  rule: a misconfiguration names the fix.
- **One obvious way.** One documented pattern per task. Agents copy the first
  example they find, so a second way to do something is how it spreads.
- **Agent context in the skeleton.** An `AGENTS.md` in `app/` holding the
  conventions, commands and boundaries, checked against the wiki so the two
  never disagree.
- **Contract test cases** (`*ContractTestCase`) for every interface an app is
  expected to implement, so a new driver or source proves it is correct.

## Default admin modules

The admin the skeleton ships today: Dashboard, Users, Files, Activity, Audit,
Jobs, Failed jobs, Scheduler, Scheduled runs, Logs, System health, Settings.

These modules are Hydra's own: every app has users, files, tokens and mail,
and needs to see them. The next ones, in priority order. Each one says what
the framework has to grow before the module can exist.

### 1. API tokens

Every issued token across all users: name, owner, abilities, last used,
expires. Revoke one, or all of a user's.

Needs: `ApiTokens` already stores them; the module is a source over that table.

Why here: a leaked token is the most urgent thing an admin needs to kill, and
the data already exists.

### 2. Rate limits

Keys currently throttled or locked out (failed sign-ins, say), with time left,
and a way to clear one.

Needs: `RateLimiter` to enumerate its keys, or a record of lockouts.

Why here: a locked-out user is a support call today, with no way to answer it
from the admin.

### 3. Sessions

Who is signed in, from where, since when. Revoke a session, or sign a user out
everywhere.

Needs: a session store that can be listed per user. `NativeSessionStore`
cannot; a database store can.

Why here: "sign out everywhere" pairs with token revocation, but it needs a new
store first.

### 4. Mail

A log of sent mail (to, subject, transport, sent at, failure), a preview of
each message, and a "send a test email" action.

Needs: the mailer to record what it sends (a listener on a sent event, so the
mail package stays storage-free).

Why here: password reset and email verification both depend on mail arriving,
and nothing shows whether it did.

### 5. Migrations

Read-only: which migrations have run, when, and which are pending.

Needs: `MigrationRunner` status as data rather than console output only.

Why here: small and read-only; what you check after a deploy.

### 6. Cache

Which store is in use, whether it is healthy, and a flush. Forget a single key.

Needs: nothing much; `CacheHealthCheck` and `StoreInterface` cover it.

Why here: small, and flushing is already a console job.

### 7. Roles and permissions

Assign roles to users, and see which abilities a role grants.

Needs: decide whether roles are data (a table the admin edits) or code (the
`Gate` abilities as written). Today they are code.

Why last: the only one that needs a design decision before any code, and it
reaches into authorization everywhere.

## Milestones

Each milestone is a set of building blocks, and ends with something you can
open in a browser. No application is a goal: each block ships with a recipe
that shows it working, and the features built on it belong to apps.

### M1. Tidy: finish what is half there

- **Done:** original filenames (0.9.17); `StorageInterface::list()`, file
  references and the Files module (0.10.0); paging as an `http` primitive,
  with paginated JSON responses and the admin rebuilt on it (0.10.1).
- The API tokens and rate limits admin modules.

### M2. Live

Building blocks:

- **Broadcasting**: `BroadcasterInterface::publish(topic, event, data)`,
  with a Redis pub/sub driver (Redis is already in the stack) and a fake for
  tests. Anything can publish: a listener, a job, a controller.
- **The SSE hub**: php-fpm cannot hold streams, since each open connection
  pins a worker and a handful of tabs would starve the pool. So streams go to
  a long-running `bin/console sse:serve` process instead: its own compose
  service (like `scheduler`), subscribed to Redis, fanning events out to
  connected browsers, with nginx proxying `/stream` to it unbuffered. Driven
  from htmx's `sse` extension.
- **Who may listen**: the page asks for a short-lived signed token naming its
  topics (`SignedToken` in `auth` already exists), and the hub checks it. The
  hub never reads sessions.
- **Live admin tables**: a listener turns `RowCreated`/`RowUpdated`/
  `RowDeleted` into a `module.<slug>` event, and a list screen re-fetches its
  own table on `sse:module.<slug>` with its current query string. The table
  is re-fetched rather than the row pushed, so sorting, filters, paging and
  per-row authorization stay right for free. Live dashboard widgets (queue
  depth, latest files) work the same way. Caveat: only writes that fire an
  event show up live, so a row written outside the admin has to publish too.
- **Notifications**: the mail-backed piece the 0.9.x changelog promised after
  password reset and email verification, plus in-app: a bell in the admin top
  bar that updates live.

Visible result: open the Users list in two tabs, sign up in a third, and watch
the row appear.

### M3. Publishing

What any site that publishes needs:

- **Markdown** rendering, with safe defaults (no raw HTML unless allowed).
- **File-backed sources**: a source that reads a directory of front-matter
  files, so content kept in git lists in the admin (read only) beside the
  database-backed modules.
- **Image variants**: resize and crop on the public disk (thumbnails,
  `srcset`). This also gives the Files module its thumbnails.
- **HTTP caching**: `ETag`, `Last-Modified`, `304 Not Modified`, and
  `Cache-Control` from `Responder`.
- **Feeds and SEO helpers**: an Atom feed and `sitemap.xml` built from a list
  of entries on request and cached, plus meta and Open Graph tags from a view
  helper.
- **Asset fingerprinting**: `app.3f2a1c.css` with far-future caching.
- **Spam helpers**: a honeypot validation rule. Per-IP throttling is already
  there in `throttle`, and gets a recipe.

### M4. Conversations

What anything people do together in real time needs, on top of M2:

- **Presence**: who is online, as short-lived keys in Redis, broadcast on
  change. Typing indicators are the same thing with a shorter life.
- **Cursor pagination**: newest first, with no OFFSET, for history and
  infinite scroll. It sits beside `Paging`, the page-number
  primitive (0.10.1).
- Sending over a plain htmx POST and receiving over SSE, as a recipe. No
  WebSockets.

### M5. Commerce

What any app that takes money needs:

- **Money**: integer minor units plus currency. Never a float.
- **Payments**: a gateway interface, with a Stripe driver as its own package.
- **Inbound webhooks**: signature checks and replay protection, for any
  provider.
- **Idempotency keys**: a middleware, so a double-click is one request.
- **Stock under concurrency**: row locks inside a transaction, as a recipe
  over the existing `transaction()`.

## Framework backlog

Building blocks with a case of their own and no milestone yet:

- **A `files` table**, recording each upload for cheap paging and `SUM(size)`
  in the Files module. Today every list page walks both disks and every file
  column, which is about 400 ms at ten thousand files. Worth it only with a
  remote disk such as S3, where listing is slow and billed. It would live in
  `admin`, so `filesystem` stays free of the database.

- **Sendfile**: `Responder` hands a file to nginx with `X-Accel-Redirect`,
  and nginx answers `Range` and `304` itself. A PHP `206 Partial Content`
  fallback covers the dev server. For any app that serves large files.
- **An HTTP client** (PSR-18), for any app that calls another service.
- **Long jobs with progress**: queued jobs that report progress over SSE, for
  imports, exports and reports. Needs M2.
- **Remember me**: "stay signed in on this device", as an opt-in in `auth`.
- **SQL helpers**, each justified by repositories that need it, never as a
  bundle: binding a list to `IN (?)`, mapping a row to a typed entity,
  single-row inserts and updates by id with columns checked against a fixed
  list, and loading related rows for a set of ids in one query.

## Ideas

Not planned. Written down so the reasoning is not lost.

- **Port Soprano off Echo.** Soprano is a self-hosted music server (about 25
  tables). The port would lean on the backlog above: sendfile, the HTTP
  client and long jobs. What stays Soprano's own: sync, transcoding, the
  player, radio, auto-playlists and its admin modules. Soprano should change
  where it made choices Hydra would not. For example, it keeps listeners
  apart from admin users but then added `is_admin` to listeners anyway, so
  one users table is the fix, not two guards in Hydra.
- **No query builder.** Soprano is the evidence: about 222 raw SQL statements
  sit beside about 225 ORM calls, because anything complex went around the
  ORM. A query builder leaves two ways to express a query, and the hard ones
  still end up in SQL. See the SQL helpers in the backlog instead.
- **Video**: HLS transcoding, chunked and resumable uploads, an S3 disk. Only
  when an app needs it.
