---
section: Views
kind: added
---
`parse()` reads a content file's YAML front matter along with its body, and leaves the block out of the HTML. Unquoted dates come back as `DateTimeImmutable`, not the Unix timestamps symfony/yaml would give, and a quoted one stays the text it says. Front matter that is malformed, or not a mapping of names to values, throws the new `Hydra\View\InvalidFrontMatter` with the line it failed on, rather than reading as empty.
