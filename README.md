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

## LLM discovery and structured data

The deployment maintenance job enables the LLM discovery modules and applies
the release-owned YAML in `config/managed/llm-discovery/`. The production site
has legitimate configuration and content that are not fully exported to this
repository, so deployment deliberately does not run a full `drush cim`.
`scripts/apply-managed-config.php` enforces a fixed allowlist, replaces only the
two release-owned discovery settings objects, merges non-secret Cloudflare
settings, and merges only the new Schema.org tags into existing Metatag
defaults.

`scripts/Test-LlmDiscovery.ps1` discovers enabled Domain records through the
production maintenance job and checks every domain's `/llms.txt`, representative
Markdown routes, and Schema.org JSON-LD. Its optional `-PurgeStale` switch calls
the Cloudflare Purge module for the exact discovered `/llms.txt` URLs and then
reruns the entire suite; it never performs a full-zone purge.
