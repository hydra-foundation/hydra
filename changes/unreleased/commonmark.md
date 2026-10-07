---
section: Views
kind: added
---
A new package, `hydrakit/commonmark`, renders Markdown through league/commonmark for `$this->markdown()`. It is safe with no configuration: raw HTML is shown as text unless the call trusts the content, and `javascript:`, `vbscript:`, `file:` and non-image `data:` links are refused whatever the trust. GitHub-flavoured tables, strikethrough, task lists and autolinks are on, headings get ids a reader can link to, links to other sites carry `rel="noopener noreferrer"`, and blocks nested past 20 levels are left as text. `MarkdownOptions` turns heading ids off, moves the nesting limit, or names the hosts that count as the site's own. Register `CommonMarkServiceProvider` (with your `MarkdownOptions`, if any) and pass `MarkdownInterface` to `PhpView` as `markdown:`.
