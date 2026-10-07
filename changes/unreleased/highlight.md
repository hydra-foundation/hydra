---
section: Views
kind: added
---
Fenced code in Markdown is highlighted on the server by tempest/highlight, so a page shows coloured code with no script and nothing new for its CSP to allow. PHP, JavaScript, CSS, HTML, SQL, Bash, JSON, YAML, Nginx, Dockerfile, Diff and the rest of its languages get `hl-*` classes for a stylesheet to colour; a language it doesn't know comes out as plain escaped code. `MarkdownOptions(highlight: false)` leaves `<pre><code class="language-…">` for a highlighter of the page's own.
