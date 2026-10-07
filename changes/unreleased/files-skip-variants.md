---
section: Admin
kind: changed
---
The Files module leaves `variants/` on the public disk out: those are `hydrakit/image`'s resized copies, not uploads. They aren't listed or counted, aren't orphans, and "Delete orphans" never touches them; `image:variants --prune` is what clears the stale ones. `FileReferences::isDerived()` says which files these are.
