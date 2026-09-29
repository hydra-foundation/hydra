---
section: HTTP
kind: added
---
Paging a list outside the admin. `Paging::fromQuery($query, 20, 100)` reads
`page` and `per_page`, clamps both, and caps the page at `Paging::MAX_PAGE` so
a typed URL can't turn into a full table scan. `Paginated` holds one page of
results. `Responder::paginated()` renders it as `data`, `meta` and relative
`links`, plus an RFC 8288 `Link` header.
