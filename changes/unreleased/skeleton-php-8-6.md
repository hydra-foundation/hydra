---
section: The skeleton
kind: changed
---
The Docker image is built on PHP 8.6. The packages still require 8.4 or newer, and `config.platform.php` stays at 8.4.0, so the lock still installs on 8.4. Two extensions are pre-releases until their 8.6 support ships stable: phpredis 6.4.0RC1 in every build and Xdebug 3.6.0alpha1 in dev builds. PCOV has no release that builds on 8.6 yet, so dev builds skip it; local coverage runs on Xdebug.

### Upgrading

An app that changes its own `docker/php/Dockerfile` to `FROM php:8.6-fpm` also has to take three more changes from the skeleton, or the build fails:

- **Opcache** is part of PHP since 8.5, so leave it out of `docker-php-ext-install`. The skeleton's line asks PHP first and works on 8.4 too.
- **`pecl` is gone** from the 8.6 images. Extensions install through PIE (`COPY --from=ghcr.io/php/pie:1-bin /pie /usr/bin/pie`, then `pie install`), and PIE needs `libtool` in the apt list.
- **phpredis and Xdebug** need the versions above. The stable releases refuse 8.6 or fail to compile on it.

Rebuild with `./bin/prod up -d --build`.
