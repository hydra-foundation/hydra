---
section: Views
kind: changed
---
`MarkdownInterface` gains `frontMatter(string $source): array`: a content file's front matter without rendering its body, so a page listing a directory of posts costs no Markdown. It throws the same `InvalidFrontMatter` as `parse()` and reads the same block, so a list and a page never disagree about where the front matter ends.

### Upgrading

A class of your own implementing `MarkdownInterface` needs the new method. `CommonMarkRenderer` has it.
