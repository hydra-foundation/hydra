---
section: The admin
kind: fixed
---
A list screen asked for a page past its end (`?page=9994` on a list with 13
pages) now shows its last page instead of "No results". This used to happen
only after a delete emptied the last page.
