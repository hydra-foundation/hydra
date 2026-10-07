---
section: Core
kind: added
---
`Versions::commit()` is the commit the checkout is on, read from `.git`'s files rather than by running `git`, so it is cheap enough to ask on every request: branches, packed refs, a detached HEAD and worktrees all answer; no repository or no commits is null. The skeleton uses it as the release mixed into every ETag when `APP_RELEASE` isn't set.
