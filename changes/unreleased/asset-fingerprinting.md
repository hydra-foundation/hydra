---
section: Views
kind: added
---
`Assets::url('/css/app.css')` returns `/css/app.3f2a1c9b0e.css`, the hash
being the file's bytes, so a stylesheet or script can be cached for a year and
an edit still reaches every browser on its next page view. It is worked out on
each request, with no manifest and no build step. Templates call
`$this->asset('/css/app.css')` once `PhpView` is given an `Assets` (the new,
optional `assets:` argument); without one it throws, naming the fix. A path
that is not a file under the public directory throws `AssetNotFound`, saying
which rule it broke.

### Upgrading

Optional. To fingerprint, pass `assets: new Assets($publicPath)` to
`PhpView`, link each stylesheet and script through `$this->asset()`, and serve
the hashed names as the skeleton's `docker/nginx/default.conf` does: a
`location` that strips the hash, marked immutable, and no `^~` prefix block
for `/css/` or `/js/` in front of it.
