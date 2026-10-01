# Roadmap

Unreleased intent. Each item graduates to a spec (in hydra-foundation's
`specs/`) when work starts, and to a changelog entry when it ships.

Last shipped: **0.22.0 — Settle** (a pause after M2: a module slug that is
not a topic segment fails at boot instead of breaking every write once live,
15-minute listen tokens with a resync after a lost stream, `key:generate` on
a fresh `.env`, errors that name their fix, `make:job`, and `AGENTS.md` in the
skeleton). Before it, **0.21.0 — Live, from anywhere: module changes**, which
finished M2.

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
- **Generators.** Every kind of class an app writes has one: `make:migration`,
  `make:controller`, `make:ability`, `make:listener`, `make:job`, and the
  table-shaped `make:admin`, `make:source`, `make:module`, `make:entity`,
  `make:repository` and `make:source-test`. The rule from here: a new
  building block with boilerplate ships its generator.
- **Errors say what to do.** The admin already does this ("Register
  FilesystemServiceProvider ahead of the AdminServiceProvider"). Make it the
  rule: a misconfiguration names the fix.
- **One obvious way.** One documented pattern per task. Agents copy the first
  example they find, so a second way to do something is how it spreads.
- **Agent context in the skeleton.** `AGENTS.md` in `app/` (0.22.0) holds the
  commands, where things go, one way per task and the boundaries; `CLAUDE.md`
  imports it. A test checks every command, script and path it names, so a
  rename fails the suite. Kept in step with the wiki by hand.
- **Contract test cases** (`*ContractTestCase`) for every interface an app is
  expected to implement, so a new driver or source proves it is correct.
  Twenty-six ship; a new interface an app implements ships with its own.

### Done: gate debt (October 2026)

What the October 2026 review found the docs owed, paid in the wiki alone:

- **Recipe pages** for the blocks that had none: files, sessions, cache, rate
  limits, events and logging. Each opens with a real task whose code was run
  against the skeleton before it was written up.
- **admin.php split**: files, sources and dashboards have pages of their own,
  and admin.php (1,300 lines, now 700) keeps the module reference. Old
  `admin.html#…` links land on a note pointing to the new page.

Kept up from here by the gate itself: a new building block ships with its page.

## Default admin modules

The admin the skeleton ships today, as thirteen sidebar entries: Dashboard,
System health, Users, Access (API tokens, with a Sessions tab), Rate limits,
Mail, Files, Activity, Audit, Logs,
Scheduler (with a Runs tab), Jobs (with a Failed jobs tab) and Settings.

These modules are Hydra's own: every app has users, files, tokens and mail,
and needs to see them. The next ones, in priority order. Each one says what
the framework has to grow before the module can exist.

**The bar for a new module.** It answers a question no current module
answers, or it does not get a sidebar entry. Status goes on System Health as a
card; an action on something a screen already shows goes on that screen; two
lists of the same kind of thing are one sidebar entry, the second a
`->tabOf()` the first (0.11.0). Each module below says what it is not.

### 1. Done: Access, sessions and API tokens (0.12.0–0.14.0)

One sidebar entry, two lists, because both are ways into an account and an
admin reaches for them for the same reason: API tokens is the `access` module,
and Sessions is `->tabOf('access')`.

- **Done: API tokens** (0.12.0). Every issued token across all users: owner,
  name, state, last used, expires. A pasted secret finds its token; revoke
  one, or all of its owner's. Tokens have no abilities: scoped tokens would
  be an `auth` feature of their own, not a column here.
- **Done: Sessions** (0.14.0). Every live sign-in: owner, address, browser,
  since when, last seen, with your own marked. Revoke one, except your own.
  Built on sign-in records in `auth` (0.13.0), which `SessionGuard` checks on
  every request.
- **Done: Sign out everywhere** (0.14.0), per user, from Users or Sessions:
  every sign-in and every token, in one action, keeping your own sign-in on
  your own account.

Left for later: a Settings screen where a person sees and ends their own
sign-ins ("your devices"). Same data, its own ownership rule.

Not: Settings › API tokens, which is one person's own tokens. When a token
leaks you usually know the token and not whose it is, so the admin needs the
list across everyone, the same split as Settings › Account and Users. Not
Activity either: that is requests made, not access that can still be revoked.

Why first: a leaked token is the most urgent thing an admin needs to kill, and
the data already existed, so tokens shipped first.

### 2. Done: Rate limits (0.15.0)

Who a limit is refusing right now (failed sign-ins, say), with time left, and
**Let back in**. Lockouts that ended in the last 15 minutes stay listed,
marked, since one under the one-minute global limit can end before anyone
looks.

Built on a record of lockouts rather than on listing the cache's keys: the
request that first goes past a budget writes a `Lockout` through
`LockoutStoreInterface`, and `RateLimiter::release()` forgets the counter and
the record.

Not: Logs, which has `auth.login_failed` lines but cannot say who is locked
out now, or let them back in.

Why here: a locked-out user is a support call today, with no way to answer it
from the admin.

### 3. Done: Mail (0.16.0)

A log of sent mail (to, subject, transport, sent at), newest first and
searchable, a preview of each message with both bodies shown as text, and
**Send a test email**, which sends to the signed-in admin in the request so a
refusing transport says why on the spot. Kept for 30 days.

Built on a sent event rather than storage in `mail`: `Mailer` dispatches
`MessageSent` after a transport accepts, and the skeleton's listener records
it, logging and swallowing its own failures so a queued mail is never sent
twice.

Left for later: sending a test to an address other than your own, which needs
a list screen to link to a form page, and the admin cannot do that yet.

Not: Failed jobs or Logs. A send that failed is already a failed job, and is
not tracked twice. What neither
shows is mail that went out, what it said, and whether the transport works.

Why here: password reset and email verification both depend on mail arriving,
and nothing shows whether it did.

### Done: on existing screens, not modules (0.17.0)

These were once planned as modules. Each became a card or an action on a
screen that already covers the subject.

- **Migrations: a System Health card.** "Up to date", or "N pending" in amber
  with the files named and the command to run: what you check after a
  deploy. Read-only. Built on `MigrationRunner::summary()`, which creates
  nothing.
- **Cache flush: an action on System Health's Cache card**, through buttons
  on dashboard cards (`Widget::action()`). The rate limiter counts in the
  cache, so a flush also ends every active lockout, and Rate limits lists
  them as Ended. Forgetting a single key is dropped: nobody browses cache
  keys, and it is a worse flush.

### Not planned: roles and permissions

Roles are code (`Role` is an enum of User and Admin), and the Users module
already assigns them. A module could only repeat that, or list abilities read
only. It becomes one if roles become data an admin edits, and that is the
design decision to make first: it reaches into authorization everywhere.

## Milestones

Each milestone is a set of building blocks, and ends with something you can
open in a browser. No application is a goal: each block ships with a recipe
that shows it working, and the features built on it belong to apps.

### M1. Done: Tidy, finish what is half there (0.9.17–0.17.0)

- **Done:** original filenames (0.9.17); `StorageInterface::list()`, file
  references and the Files module (0.10.0); paging as an `http` primitive,
  with paginated JSON responses and the admin rebuilt on it (0.10.1); module
  tabs, with Scheduler and Runs, and Jobs and Failed jobs, each one entry
  (0.11.0); Access › API tokens (0.12.0); sign-in records (0.13.0); Access ›
  Sessions and sign out everywhere (0.14.0); lockout records and the Rate
  limits module (0.15.0); the sent event and the Mail module (0.16.0);
  migration state as data, buttons on dashboard cards, and System Health's
  Migrations card and cache flush (0.17.0).

### M2. Done: Live (0.18.0–0.21.0)

- **Done (0.18.0):** broadcasting, the SSE hub and who may listen, all in
  `hydrakit/broadcast`.
  - `BroadcasterInterface::publish(topic, event, data)` has `redis`, `log`
    and `null` drivers and a fake. It's best effort: a Redis outage is a
    warning, never a failed write.
  - `bin/console sse:serve` is its own compose service, with nginx proxying
    `/stream` to it unbuffered. It holds the streams php-fpm cannot, with a
    heartbeat, a connection cap and a resync after Redis returns.
  - A page gets a short-lived `StreamToken` for its topics from
    `/stream/token`, where `TopicPolicy` decides, deny by default. The hub
    checks the token and never reads sessions. The token is its own class
    on core's `Signer`, not auth's `SignedToken`.
  - A small stream client in the skeleton's `app.js` turns events into
    `sse:<topic>` DOM events for `hx-trigger`, with no htmx extension.
  - The skeleton shows it on System Health and in a home-page demo.

- **Done (0.19.0):** live admin tables.
  - The admin publishes its own writes and row actions on `module.{slug}`,
    and grants the topic to whoever may open the module.
  - Every list listens with no opt-in, and refetches its own table as
    shown after half a second of quiet: filter, sort, page and fresh
    tallies, with no URL pushed.
  - Rows written outside the admin go live when their writer publishes,
    which is the opt-in. The skeleton's `UserRepository` does, for
    `make:user`, settings and email changes.
  - Dashboard cards and the summary strip listen with `Widget::liveOn()`,
    and the skeleton's Totals and Newest accounts do.
  - Measured on the dev stack at 10,000 users: a refetch is about 40 ms for
    the body and 35 ms for the tallies.
  - Not yet: Activity, Jobs, Mail and Sessions, whose writers don't publish.
    Activity needs its own refetches excluded and its publishing
    throttled, or an open Activity tab would feed itself.

- **Done (0.20.0):** notifications.
  - `Notifier::notify($userId, new Notice(...))` keeps a notice, and
    publishes only the unread count on `user.{id}`, which is granted to
    that user alone.
  - A bell at the end of the admin's account row has an unread badge that
    updates live, the latest ten, Mark all read and Clear read. Opening a
    notice follows its link.
  - The application implements `NotificationStoreInterface`, proved by its
    contract test case. The skeleton keeps a `notifications` table and
    prunes read notices after 90 days.
  - There is no channel system. Mail stays where it is, and the skeleton's
    security notices both mail and notify.

- **Done (0.21.0):** module changes, the follow-up for rows written outside
  the admin.
  - `Live\ModuleChanges::publish($slug, $id)` owns the `module.{slug}` topic
    and does nothing without a broadcaster, so a writer can always depend on
    it. `PublishAdminEvents` goes through it too.
  - `hydrakit/queue` dispatches `QueueChanged` (the tables that moved) on a
    push, a retry, cancel, forget or flush, and once per worker batch, not
    per job. The queue still knows nothing of the admin.
  - The skeleton's Jobs, Failed jobs, Mail, Access › Sessions and Audit are
    live. Sessions never publishes on the per-request touch, and never sends
    a sign-in's id.
  - Activity, Logs and Rate limits stay snapshots on purpose: their rows are
    written on every request, and Activity would feed itself. There is no
    time-based throttle; the one busy writer, the worker, coalesces per batch.
  - There is no `->live()` flag on a module: every list already listens, and
    what makes a table live is its writers publishing.

Visible result: open the Users list in two tabs, create a user in a third (or
run `make:user`), and watch the row appear. The skeleton has no public sign-up.

### Done: Settle (0.22.0)

A review of M1 and M2 against this roadmap and the code, and a release of
what it found: one bug (module slugs), the listen-token lifetime, the stream
client's resync, the nginx error log, `key:generate`, five errors, and the
gate items two milestones had skipped (`make:job`, `AGENTS.md`). What it found
and did not fix is under "Gate debt" and in the backlog.

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
  imports, exports and reports. The hub it needs shipped in 0.18.0.
- **Scheduled runs and Files, live.** The same `ModuleChanges` recipe, for
  the two monitoring lists 0.21.0 left out. Scheduled runs needs an event
  from `hydrakit/scheduler`'s run log, as the queue has `QueueChanged`.
  Files needs the admin's `Uploads` to know the Files module's slug, or the
  application to publish around every upload.
- **Remember me**: "stay signed in on this device", as an opt-in in `auth`.
- **After commit**: `ConnectionInterface::afterCommit(fn)`, run at once
  outside a transaction, so a publish inside one waits for the commit. Today
  the rule is documented (live.php: publish after the write commits) and no
  skeleton writer publishes inside a transaction. Decided against Principle 3
  with the SQL helpers, when a second writer needs it.
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
