---
section: The skeleton
kind: added
---
A user's avatar keeps the name it was uploaded under, in a new `users.avatar_name` column, whether an admin sets it from the Users module or the user sets it from their account settings. It downloads under that name, and removing the picture clears both.

### Upgrading

Run the new migration, which adds `users.avatar_name`. Then copy the changes to `Avatar`, `User`, `UserRepository`, `UserSource` and `SettingsController`. Avatars uploaded before this have no name, and download under their key.
