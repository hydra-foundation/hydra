---
section: The filesystem
kind: added
---
`Filename::clean()` turns the name a client sent with an upload into one worth keeping: the path in front of it, control bytes and stray whitespace go, unicode stays, and the extension is made to agree with the bytes by adding the true one, so `evil.php` holding a PNG is kept as `evil.php.png`. It never touches a key.
