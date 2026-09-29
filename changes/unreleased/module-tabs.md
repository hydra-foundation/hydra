---
section: The admin
kind: added
---
A module can be a tab of another module with `->tabOf('jobs')`. It keeps its
own slug, URL, source and screens, but gives up its own sidebar entry: the
parent's entry stands for the family, stays highlighted on every tab, and leads
to the first member the visitor may open. Every screen in the family shows a
strip of tabs under its heading, leaving out any the gate denies. A tab of
itself, a tab that also declares `group()`, a tab of a module that isn't
registered and a tab of a tab all fail at boot (and in `admin:check`) with a
message that names the fix.

### Upgrading

The strip is the shipped `admin/partials/tabs`, and its classes are
`.admin-tabs` and `.admin-tab`. They replace `.settings-nav` and
`.settings-tab`, which `admin.css` no longer styles: a view of your own that
used them, such as a copy of the skeleton's `admin/settings/nav.php`, should
render `admin/partials/tabs` instead.
