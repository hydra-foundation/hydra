---
section: The admin
kind: fixed
---
A module's slug is checked when it is defined. A slug is also its broadcast
topic (`module.{slug}`), and a capital, a space or a dot made every admin
write store its row and then answer 500 once a broadcaster was bound, so a
retry made a duplicate. `Definition::make()` now refuses anything but
lowercase a-z, 0-9, `-` and `_` (at most 64 characters), and suggests the
nearest slug that is.

### Upgrading

A module whose slug breaks the rule now fails at boot, broadcaster or not,
with the suggestion in the message. Renaming a slug changes the module's URLs
and the module name its new audit rows carry; older audit rows keep the old
one.
