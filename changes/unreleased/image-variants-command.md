---
section: Images
kind: added
---
`php bin/console image:variants` makes every picture's copies now, rather than on a reader's first view: run it in a deploy. It walks the public disk and the directories `ImageOptions(directories:)` names under the document root (`/images` by default), for every preset. `--prune` then removes the copies no picture and preset would name any more, those of edited, deleted or re-preset pictures; a run where any picture failed prunes nothing, since a page may still name it.
