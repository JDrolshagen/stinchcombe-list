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

## JSON:API and Codex

Drupal's core JSON:API is enabled by the production deployment. Its discovery
document is available at `https://stinchcombelist.com/jsonapi` using the media
type `application/vnd.api+json`.

This repository also includes a project-scoped MCP bridge in `.codex/`. After
trusting this repository and starting a new Codex task, Codex can discover and
read the site's public JSON:API resources through the `stinchcombe_jsonapi`
tools.

The bridge defaults to read-only public access. To allow authenticated JSON:API
writes, copy `.codex/jsonapi.env.example` to `.codex/jsonapi.env` and supply a
dedicated, least-privilege Drupal account. The local credentials file is
ignored by Git. The deployment enables Drupal's core `basic_auth` provider,
but the dedicated account still controls which resources Codex may change.
