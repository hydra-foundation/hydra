---
section: The admin
kind: added
---
`Input::file()` declares a file control, and a form holding one posts multipart.

### Upgrading

Register `FilesystemServiceProvider` before declaring a file control:

```php
$app->register(FilesystemServiceProvider::class);
```
