# Hydra SEO

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/seo` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/seo`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

What a public site owes search engines, link previews and feed readers, from
plain values. `SiteMeta` holds what every page shares (the base URL, the site
name, a default share image) and makes one `Meta` per page: the `<title>`, the
description, the canonical URL, Open Graph and Twitter tags, printed by the
layout with `<?= $meta ?>`. Every value is escaped here, once, and every URL it
prints is absolute, because a preview or a crawler cannot resolve a relative
one.

None of it reads files, the database or the clock behind the caller's back:
what is expensive to read is the caller's to cache.
