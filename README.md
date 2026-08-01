# The Stinchcombe List

Drupal 11 codebase for The Stinchcombe List, managed with Composer and based on
Pantheon's integrated Composer workflow.

## Requirements

- PHP 8.3
- Composer 2

## Install

```sh
composer install
```

The Drupal document root is `web/`. Environment-specific credentials and API
keys must be supplied outside version control.

## Dependency updates

Drupal core and contributed projects are constrained in `composer.json` and
resolved in `composer.lock`. After changing dependencies, validate with:

```sh
composer validate --no-check-publish
composer audit --locked
composer check-platform-reqs --no-dev
```

## Production deployment

Every push to `main` builds and deploys the container to the `stinchcombe-list`
service in Google Cloud Run. Production is available at
https://stinchcombelist.com/.

Deployment details and the manual provisioning script are in `infra/`.
