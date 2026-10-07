---
section: Views
kind: added
---
`Hydra\View\Content\ContentDirectory` reads a directory of Markdown files with front matter, such as a blog's posts kept in git. `all()` lists every `*.md` whose name is a slug (`[a-z0-9-]+`), by slug, reading front matter only; `find($slug)` reads one, and anything that isn't a slug never reaches the disk; `document($file, trusted: true)` renders it. Each `ContentFile` carries its slug, path, modified time, front matter and body source. A file whose front matter won't parse is still listed, with empty meta and the reason in `error`, so one bad post never hides the rest. A directory that doesn't exist throws, naming the path.
