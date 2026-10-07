---
section: Requirements
kind: changed
---
Hydra now needs PHP 8.4 or newer; 8.2 and 8.3 are no longer supported. 8.2 has had security fixes only since December 2024 and loses them at the end of 2026, the skeleton's Docker image has run 8.4 from the start, and the next release's code highlighting needs it. CI tests 8.4 and 8.5.

### Upgrading

Run the app on PHP 8.4 or newer: the skeleton's Docker stack already does. In a project made from the skeleton, set `"php": ">=8.4"` in `composer.json`, `config.platform.php` to `"8.4.0"`, `phpVersion.min` to `80400` in `phpstan.neon.dist`, and run `composer update`. A server on stock Ubuntu 24.04 (PHP 8.3) needs a newer PHP from a PPA, or Docker.
