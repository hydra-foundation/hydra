---
section: Views
kind: added
---
`$this->markdown($text)` prints Markdown as HTML from any view, through the new `MarkdownInterface` contract in `view`. Raw HTML in the source is shown as text unless the call says `trusted: true`, which is for content the site's owner wrote. `parse()` reads a content file into a `Document`: its front matter and its body as HTML. `PhpView` takes the renderer as a new optional `markdown:` argument, and `markdown()` says what to pass when none was given.
