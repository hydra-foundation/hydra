---
section: The admin
kind: fixed
---
The account slot in the top bar and the rail now updates when you move between screens. A frame swap sends it out of band to both places, so a picture or name changed on one screen shows up without a reload.

### Upgrading

To opt in, put your account markup in `views/admin/partials/account.php`. It shadows the admin's empty default, which the frame fragment renders. Then pass `trim($this->partial('admin/partials/account'))` as the shell's `account`. An application that keeps its markup elsewhere still gets the slot on full page loads, but not on swaps.
