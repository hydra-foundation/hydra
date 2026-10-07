# Hydra CommonMark

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/commonmark` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/commonmark`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Markdown to HTML a public page can print, through
[league/commonmark](https://commonmark.thephpleague.com). It implements
`hydrakit/view`'s `MarkdownInterface`, so a view says `<?= $this->markdown($text) ?>`
and never names the library.

Safe without configuration: raw HTML in the source is shown as text unless the
call says the content is trusted, and links with an unsafe scheme
(`javascript:` and its kin) are refused whatever the trust. GitHub-flavoured
tables, task lists and autolinks are on, headings get ids a reader can link to,
external links carry `rel="noopener noreferrer"`, and fenced code is
highlighted on the server by [tempest/highlight](https://github.com/tempestphp/highlight),
so a post needs no JavaScript to show coloured code. `parse()` reads a content
file's YAML front matter as well, dates included.
