---
section: Admin
kind: added
---
`Hydra\Admin\Sources\ContentSource` lists a `ContentDirectory` in the admin, declared like a `TableSource`: `columns`, `sortable`, `searchable`, `filterable`, `defaultSort`. A row is a file's front matter plus `id` and `slug`, `body`, `modified_at` and `problem`, which every row has without declaring. Search, sort (dates and numbers as such, text naturally, ties by slug), filters and day ranges all run in memory; a list column such as `tags` matches a filter when it contains the value. `map:` adds the columns a site derives, such as a post's status, and sees dates and lists as they were read; a row is flattened for printing after it, dates to ISO 8601 and lists joined with ", ". A file whose front matter won't parse is listed with the reason in `problem`. Read only, and `admin:check` checks a module over it like any other.
