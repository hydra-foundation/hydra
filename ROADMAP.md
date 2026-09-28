# Roadmap

Unreleased intent. Each item graduates to a spec (in the hydra-foundation root)
when work starts, and to a changelog entry when it ships.

Last shipped: **0.9.16 — Faces** (filesystem, file uploads, avatars).

## Default admin modules

The admin the skeleton ships today: Dashboard, Users, Activity, Audit, Jobs,
Failed jobs, Scheduler, Scheduled runs, Logs, System health, Settings.

The next modules, in priority order. Each one says what the framework
has to grow before the module can exist.

### 1. Files

See every file the app has stored and how much space it takes.

- **List**: thumbnail or type icon, original name, type, size, disk
  (private/public), modified at. Filter by disk and type, search by name.
- **Show**: preview (images inline, like `/admin/files` already serves),
  download under the original name, which row points at it.
- **Delete**: warns when a row still points at the file.
- **Orphans**: files no row points at any more (a failed cleanup, a crash
  mid-write), with a bulk delete.
- **Dashboard widget**: file count and bytes used per disk, and the latest
  files.

Needs, in order:

1. **Original filenames.** Today the name is thrown away: the key is built
   from the MIME type (`avatars/<32 hex>.png`) and `FileController` serves a
   download with no filename, so it arrives as a hash. `Uploads::store()`
   keeps the client's filename (sanitised) and `FileController` sends it in
   `Content-Disposition`. Where the name lives is for the spec: beside the
   file on the disk, so `list()` sees it with no database, is the lean
   option; a column beside the key is the other. This lands on its own, before
   the module, since a name not kept at upload is gone for good.
2. **`StorageInterface::list()`**: walk a disk and return each file's key,
   size, MIME type and modified time. Added to the storage contract test case.
3. **References**: collect the keys held by every `Input::file()` column the
   registered modules declare, and compare with what `list()` returns. That
   answers "which row points at it" and finds the orphans, with no registry.

Later, if it is ever needed: a `files` table recording each upload (uploader,
owner row) for cheap paging and `SUM(size)`. Worth it only with a remote disk
such as S3, where listing is slow and billed. It lives in `admin`, so
`filesystem` stays free of the database.

### 2. API tokens

Every issued token across all users: name, owner, abilities, last used,
expires. Revoke one, or all of a user's.

Needs: `ApiTokens` already stores them; the module is a source over that table.

Why here: a leaked token is the most urgent thing an admin needs to kill, and
the data already exists.

### 3. Rate limits

Keys currently throttled or locked out (failed sign-ins, say), with time left,
and a way to clear one.

Needs: `RateLimiter` to enumerate its keys, or a record of lockouts.

Why here: a locked-out user is a support call today, with no way to answer it
from the admin.

### 4. Sessions

Who is signed in, from where, since when. Revoke a session, or sign a user out
everywhere.

Needs: a session store that can be listed per user. `NativeSessionStore`
cannot; a database store can.

Why here: "sign out everywhere" pairs with token revocation, but it needs a new
store first.

### 5. Mail

A log of sent mail (to, subject, transport, sent at, failure), a preview of
each message, and a "send a test email" action.

Needs: the mailer to record what it sends (a listener on a sent event, so the
mail package stays storage-free).

Why here: password reset and email verification both depend on mail arriving,
and nothing shows whether it did.

### 6. Migrations

Read-only: which migrations have run, when, and which are pending.

Needs: `MigrationRunner` status as data rather than console output only.

Why here: small and read-only; what you check after a deploy.

### 7. Cache

Which store is in use, whether it is healthy, and a flush. Forget a single key.

Needs: nothing much; `CacheHealthCheck` and `StoreInterface` cover it.

Why here: small, and flushing is already a console job.

### 8. Roles and permissions

Assign roles to users, and see which abilities a role grants.

Needs: decide whether roles are data (a table the admin edits) or code (the
`Gate` abilities as written). Today they are code.

Why last: the only one that needs a design decision before any code, and it
reaches into authorization everywhere.

## Framework

- **Uncouple pagination from the admin.** Paging only exists inside
  `Hydra\Admin` today: `Page` holds the rows and total, and `Criteria` mixes
  the page and per-page with sort, filters, search and filter links. An API
  controller that wants to page a list has nothing to reach for.
  - Pull the paging half out into a framework primitive, with no knowledge of
    the admin: page number, per-page, offset, total, pages, `from`/`to`,
    has-previous/has-next, and the `MAX_PAGE` cap (the full-scan guard) with
    it. It also reads `page` and `per_page` off a `Query`, with per-page
    clamped to a ceiling the caller sets.
  - **Paginated responses**: `Responder` renders a page as JSON, meaning the
    rows plus `meta` (page, per page, total, pages) and `links` (first, prev,
    next, last) built from the request URL, and an RFC 8288 `Link` header.
  - The admin's `Page` and `Criteria` are rebuilt on the primitive, keeping
    sorting, filters and search as admin concerns. The `SourceInterface`
    contract does not change for a module author.
  - Lives in `http`, beside `Query` and `Responder`. Paging is not
    database-specific (the log and schedule sources page arrays), so not
    `database`.

- **Realtime (SSE)**: server-sent events, driven from htmx's `sse` extension.
  - **Broadcasting**: `BroadcasterInterface::publish(topic, event, data)`,
    with a Redis pub/sub driver (Redis is already in the stack) and a fake for
    tests. Anything can publish: a listener, a job, a controller.
  - **The hub**: php-fpm cannot hold streams, since each open connection pins
    a worker and a handful of tabs would starve the pool. So streams go to a
    long-running `bin/console sse:serve` process instead: its own compose
    service (like `scheduler`), subscribed to Redis, fanning events out to
    connected browsers, with nginx proxying `/stream` to it unbuffered.
  - **Who may listen**: the page asks for a short-lived signed token naming
    its topics (`SignedToken` in `auth` already exists), and the hub checks
    it. The hub never reads sessions.
  - **Live admin tables**: a listener turns `RowCreated`/`RowUpdated`/
    `RowDeleted` into a `module.<slug>` event, and a list screen re-fetches
    its own table on `sse:module.<slug>` with its current query string. The
    table is re-fetched rather than the row pushed, so sorting, filters,
    paging and per-row authorization stay right for free. Live dashboard
    widgets (queue depth, latest files) work the same way.
  - Caveat: only writes that fire an event show up live. A row written
    outside the admin (a sign-up on the front end, say) needs to publish too.
- **Notifications**: the mail-backed piece the 0.9.x changelog promised after
  password reset and email verification. With realtime, also in-app: a bell
  in the admin top bar that updates live.

## What Hydra is for

The long-term goal is to build these apps on Hydra:

- **Blog and landing page**: williamhleucka.com, running on Hydra 0.2 today.
- **Media streaming**: Soprano, a music server running on Echo today. Video
  comes later.
- **Chat**
- **Shop** with a cart

Each milestone below ends with something you can open in a browser and builds
a piece of the foundation those apps need. Two of the apps already exist, so
porting them is how the framework gets used for real rather than only tested.

## Milestones

### M1. Tidy: finish what is half there

Original filenames, `StorageInterface::list()`, the Files module, and paging
out of the admin with paginated JSON responses. Then API tokens and rate
limits in the admin.

### M2. Live

Realtime: the broadcaster, the SSE hub, live admin tables and widgets, and
notifications (mail and in-app).

Visible result: open the Users list in two tabs, sign up in a third, and watch
the row appear.

### M3. Publish: port williamhleucka.com

The site runs on Hydra 0.2: markdown posts with YAML front matter in
`content/blog/`, read by a `PostRepository` over the directory; a `comments`
table keyed by `post_slug` with a `pending`/`approved` status; a hand-written
`AdminCommentsController`; a contact form; feed and sitemap console commands;
and its own `Mail` wrapper over `symfony/mailer`. The port moves it to the
current Hydra and swaps the hand-written admin for modules.

Framework:

- **Markdown** rendering, with safe defaults (no raw HTML unless allowed).
  The site already uses `league/commonmark`, so this is wiring more than code.
- **File-backed content**: posts stay markdown files in git. A source that
  reads a directory of front-matter files lets the admin list posts (read
  only) beside the database-backed modules.
- **Image variants**: resize and crop on the public disk (thumbnails,
  `srcset`), which also gives the Files module its thumbnails.
- **HTTP caching**: `ETag`, `Last-Modified`, `304 Not Modified`, and
  `Cache-Control` from `Responder`.
- **Feeds and SEO**: Atom feed and `sitemap.xml` built on request and cached,
  rather than generated by a console command, plus meta and Open Graph tags
  from a view helper.
- **Asset fingerprinting**: `app.3f2a1c.css` with far-future caching.

Admin, in the site:

- **Comments module**: the moderation queue, with filter links (pending,
  approved, spam), approve, reject and mark-as-spam as row actions, bulk
  approve, and a link through to the post. With M2, the pending count is a
  live dashboard widget and a new comment raises an in-app notification.
- **Contact messages module**: messages are stored as well as emailed, so a
  lost email is not a lost message.
- **Spam**: a honeypot field and a throttle per IP, on comments and contact.

The port also drops what Hydra now owns: the `Mail` wrapper goes (use
`hydrakit/mail`), and users, auth and audit come from the skeleton.

### M4. Talk: chat

- Rooms, messages and membership.
- Messages arrive over SSE and are sent with a plain htmx POST, so no
  WebSockets are needed.
- **Presence** (who is online) and typing indicators: short-lived keys in
  Redis, broadcast on change.
- Unread counts, and history loaded with cursor pagination (newest first,
  with no OFFSET).
- Rate limits on sending, and moderation (delete, mute) as an admin module.

Example app: a chat with rooms.

### M5. Listen: port Soprano off Echo

Soprano is a self-hosted music server (music, radio, podcasts) running on
Echo, the framework before Hydra. It is the biggest app on this list: about 25
tables, an Echo `Model`/`QueryBuilder` layer with relations, 15 dashboard
widgets, and a Twig and htmx front end.

Hydra needs these before the port:

- **A query builder**, with relations or something close to them. Echo had
  one, and Soprano leans on it everywhere. Hand-written SQL in 25 repositories
  is not a port anyone finishes.
- **An HTTP client** (PSR-18). Soprano calls LRCLIB, MusicBrainz, Wikidata
  and Listen Notes, and Hydra has no client today.
- **Sendfile**: `Responder` hands a file to nginx with `X-Accel-Redirect`,
  which is how Soprano streams now. nginx then answers `Range` and `304`
  itself. A PHP `206 Partial Content` fallback covers the dev server.
- **Remember me**: "sign in once per device". Soprano has
  `client_remember_tokens`, and `hydrakit/auth` has no equivalent.
- **A second kind of user**: Soprano keeps listeners (`clients`) apart from
  admin `users`. Either two guards, or one users table with an admin flag
  (Soprano already added `is_admin` to clients, which suggests the latter).
- **Long jobs**: library sync, Opus transcoding (ffmpeg with ReplayGain baked
  in), lyrics and artist-image backfills, and Essentia feature extraction,
  all as queued jobs with progress over SSE, instead of Echo's `jobs/*.php`.

What stays Soprano's own: sync, transcoding, the player, radio stations,
auto-playlists, the year-end review, and its admin modules (tracks, albums,
artists, radio stations) and listening widgets.

Echo had admin pieces Hydra's default admin does not, which are worth
reading before writing Hydra's versions: `file_info` with a Files module and
widget, an email queue module, per-module user permissions, a Redis widget,
and an activity map from GeoIP.

Video (HLS transcoding, chunked and resumable uploads, an S3 disk) comes
after Soprano, when an app needs it.

### M6. Shop: store with cart

- **Money**: integer minor units plus currency. Never a float.
- **Cart** in the session, carried over when a guest signs in.
- **Products and variants**, with stock kept correct under concurrency (row
  locks inside a transaction).
- **Checkout** with idempotency keys, so a double-click is one order.
- **Payments**: a gateway interface with a Stripe driver, and inbound webhooks
  with signature checks and replay protection.
- Order emails, and Orders and Products admin modules.

Example app: a small store you can check out of in Stripe's test mode.

Open: where the chat and shop example apps live (an `examples/` directory, or
a repo each). The site and Soprano already have their own repos.
