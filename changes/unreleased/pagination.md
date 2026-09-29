---
section: HTTP
kind: added
---
`Paging` reads `page` and `per_page` off a `Query` for any list, not only the
admin's. It clamps both, caps the page at `Paging::MAX_PAGE` so a typed URL
can't turn into a full table scan, and gives the offset. `Paginated` is one
page of results: its items, the total, and `pages()`, `from()`/`to()` and
has-previous/has-next, plus `map()` to reshape the items.
