---
section: The admin
kind: added
---
A module can be a tab of another module with `->tabOf('jobs')`. It keeps its
own slug, URL, source and screens, but gives up its own sidebar entry: the
parent's entry stands for both. A tab of itself, a tab that also declares
`group()`, a tab of a module that isn't registered and a tab of a tab all fail
at boot (and in `admin:check`) with a message that names the fix.
