# Hydra

Development monorepo for the Hydra PHP framework.

## Layout

    packages/<name>/     one composer package each, published as hydrakit/<name>
    composer.json        the root manifest: replaces every hydrakit/* package
    phpunit.xml          one testsuite per package
    bin/                 release, test and status scripts

Every `packages/*` directory keeps its own `composer.json` and `phpunit.xml`.
Those are what the split publishes, and what makes each package installable
and testable on its own. The root manifest is for development only: it is
never published, and `replace` keeps Composer from trying to fetch a sibling
from Packagist while you are editing it.

## The package repositories are generated

`hydra-foundation/core`, `hydra-foundation/http` and the fourteen others are
**outputs**. The split workflow regenerates them from this repository on every
push to `main` and every tag. A commit pushed directly to one of them will be
overwritten without warning. All development happens here.

Consumers are unaffected: they still `composer require hydrakit/http`, and
still get it from Packagist.

## Working on it

    composer install
    vendor/bin/phpunit

The suite runs all packages in one process, so state that leaks between them
through `putenv()`, `$_ENV` or static properties is a real failure mode. CI
runs `--order-by=random` for that reason; run it that way locally too.

To see a framework change in the skeleton, install the app against this
checkout rather than against Packagist:

    cd ../app && COMPOSER=composer.dev.json composer install

That resolves every `hydrakit/*` from `../hydra/packages/*` by symlink, so
edits here are live in the app with no reinstall.

## Releasing

    ../release.sh 0.3.1 --push          # patch or minor inside the 0.3 series
    ../release.sh 0.4.0 --minor --push  # crossing to 0.4 rewrites ^0.3 first

Two repositories are tagged, this one and `app`. The split workflow then
regenerates the sixteen package repositories at the same tag and Packagist
indexes them.
