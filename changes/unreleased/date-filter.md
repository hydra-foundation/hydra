---
section: The admin
kind: added
---
A list can be narrowed by date. `filterable()` on a `date` or `datetime` field now draws a From and a To day in the toolbar instead of an exact match, read as `{field}_from` / `{field}_to` in the URL. Both ends are whole days in the reader's zone, either can be left open, and the range carries into the pager, the export and the filter links' counts. `Hydra\Admin\DateRange` does the reading: `condition()` for a source that writes SQL, `contains()` for one that holds its rows in memory.
