---
section: The admin
kind: added
---
A module can be a tab of another module with `->tabOf('jobs')`. It keeps its
own slug, URL, source and screens, but gives up its own sidebar entry: the
parent's entry stands for both. A tab of itself, or a tab that also declares
`group()`, fails when the module is compiled, with a message that names the fix.
