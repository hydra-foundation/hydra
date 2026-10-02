---
section: SEO
kind: added
---
A new package, `hydrakit/seo`. `SiteMeta` holds what every page of a site
shares (its base URL, its name, a default share image) and makes one `Meta` per
page: `$site->page('Resume', 'What it says', '/resume')` prints the `<title>`,
the description, the canonical URL, and the Open Graph and Twitter tags a link
preview reads, from `<?= $meta ?>` in the layout. Every value is escaped once,
in the package, and every URL it prints is absolute; a base URL with a path or
a trailing slash, or a page path that is not from the site root, is refused
with a message naming the fix.
`$site->article(...)` does the same for a post, adding its dates, its tags and
its own share image; `withNoIndex()`, `withFeed('Writing', '/feed.xml')` and
`withTitle('Name', format: false)` return a changed copy. A description is
collapsed to one line and cut on a word at 200 characters, since front matter
is written without counting.
`Sitemap` builds `sitemap.xml` from paths and the time each last changed:
absolute `<loc>`s, `<lastmod>` in UTC, no `priority` or `changefreq` (Google
ignores both). A path added twice is listed once, with the later time, and
past 50,000 URLs it refuses rather than write a file crawlers reject.
