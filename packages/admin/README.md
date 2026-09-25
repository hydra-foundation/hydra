# Hydra Admin

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/admin` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/admin`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

A composable admin backend for Hydra apps. Modules declare *what* they are (fields, a source, screens, etc)
and the package compiles that into ordinary routes, a gate-filtered sidebar, and htmx-driven screens.

## Views

The package ships the templates it renders, in `views/`. Hand that directory to
the view as a fallback and the admin renders without any templates of your own:

```php
new PhpView(
    __DIR__ . '/views',
    $csrf,
    fallbacks: [AdminServiceProvider::views()],
);
```

To change one of them, put a file of the same name in your own views
directory. `views/admin/partials/table.php` replaces the shipped table, and
everything else still comes from the package. Nothing is registered or published for that to
work; the file simply wins.

Two things the package deliberately does not ship, because they belong to the
site rather than to the admin:

- `layouts/admin`, the seam at which the admin attaches to your own page
  chrome. It renders `admin/partials/shell` inside whatever layout your site
  already has, and that is all it does:

  ```php
  <?php $this->extends('layouts/base') ?>

  <?= $this->partial('admin/partials/shell', ['screen' => $screen, 'content' => $this->section('content')]) ?>
  ```

- The templates your own `PageScreen`s name, such as a dashboard.

### Swap depths

htmx swaps against two ids, both declared by shipped templates and both read
back by `Renderer`: `admin-frame` (the whole screen, what a sidebar link
replaces) and `admin-body` (just the table, what a filter or a page link
replaces). They are literal strings in the markup on purpose, because a
stylesheet and a template are what a designer edits, not a PHP constant, and
`ShippedViewsTest` fails if a target, a declaration, and `Renderer` ever stop
agreeing.

### The stylesheet and the script

`assets/admin.css` and `assets/admin.js` are the package's, not yours. They are
served at `{prefix}/assets/stylesheet` and `{prefix}/assets/script` rather than
copied into your `public/` directory, because a copy has to be re-made on every
upgrade and the one nobody re-made looks exactly like the one nobody needed:
the admin renders, and only the field type the new version added is unstyled.
Link them from your `layouts/admin`:

```php
<link rel="stylesheet" href="/admin/assets/stylesheet" />
<script src="/admin/assets/script" defer></script>
```

The URLs carry no extension deliberately. A web server's static-file rules are
written against extensions, and a `.css` under a path with no file behind it is
a 404 from the server before PHP is reached — nginx's stock
`location ~* \.(css|js)$` does precisely that. Extensionless, they fall through
to the front controller everywhere, which is the point: no per-application
server configuration.

Both are served with an `ETag` and `must-revalidate`, so a browser spends one
conditional request per page load and gets a 304. The route is registered ahead
of the module routes, and without the admin's middleware — a sign-in screen has
to be styled before anyone has signed in.

To override a rule, link a sheet of your own *after* this one. To replace the
sheet entirely, do not link this one at all.

### The theme contract

`admin.css` names no colour. Every one comes from a custom property your
application defines, which is what lets the sheet ship here without bringing a
palette with it, and what lets your themes reach into the admin. Define these
on `:root`, or the admin renders with whatever the browser makes of an empty
value:

- `--accent`
- `--accent-hover`
- `--accent-wash`
- `--danger`
- `--danger-line`
- `--danger-wash`
- `--ease`
- `--font-display`
- `--font-mono`
- `--font-text`
- `--hover-tint`
- `--ink`
- `--ink-strong`
- `--line`
- `--line-strong`
- `--muted`
- `--ok`
- `--paper`
- `--r-1`
- `--r-2`
- `--r-3`
- `--s-1`
- `--s-2`
- `--s-3`
- `--s-4`
- `--s-5`
- `--s-6`
- `--s-7`
- `--scrim`
- `--sunk`
- `--surface`
- `--t-base`
- `--t-data`
- `--t-display`
- `--t-h1`
- `--t-h3`
- `--t-lead`
- `--t-micro`
- `--t-small`
- `--t-stat`
- `--warn`

`ShippedAssetsTest` holds both halves: the sheet may name no colour, and it may
not ask for a token this list omits.
