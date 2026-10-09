---
section: The skeleton
kind: fixed
---
The public pages keep the site's palette when an admin is signed in. The theme picked under Settings is the admin's appearance, but the shared layout painted every page in it, the home page included. `layouts/base.php` now defaults to the fallback palette and `layouts/admin.php` names the signed-in person's.

### Upgrading

An app made from the skeleton merges the change to `views/layouts/base.php` (the `data-theme` default) and `views/layouts/admin.php` (the `theme` section). A layout of its own that should wear the admin's palette starts the same section.
