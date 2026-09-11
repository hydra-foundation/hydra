# Hydra Admin

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

To change one of them, put a file of the same name in your own views directory —
`views/admin/partials/table.php` replaces the shipped table, and everything else
still comes from the package. Nothing is registered or published for that to
work; the file simply wins.

Two things the package deliberately does not ship, because they belong to the
site rather than to the admin:

- `layouts/admin` — the seam at which the admin attaches to your own page
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
replaces). They are literal strings in the markup on purpose — a stylesheet and
a template are what a designer edits, not a PHP constant — and `ShippedViewsTest`
fails if a target, a declaration, and `Renderer` ever stop agreeing.
