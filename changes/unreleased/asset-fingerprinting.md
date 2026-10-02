---
section: Views
kind: added
---
`Assets::url('/css/app.css')` returns `/css/app.3f2a1c9b0e.css`, the hash
being the file's bytes, so a stylesheet or script can be cached for a year and
an edit still reaches every browser on its next page view. It is worked out on
each request, with no manifest and no build step. A path that is not a file
under the public directory throws `AssetNotFound`, saying which rule it broke.
