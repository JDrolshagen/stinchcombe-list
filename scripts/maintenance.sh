#!/usr/bin/env sh
set -eu

cd /var/www/html
DRUSH=/var/www/html/vendor/bin/drush

managed_modules="token token_filter llms_txt domain_llms_txt markdownify markdownify_path markdownify_views schema_metatag schema_article schema_web_page schema_web_site cloudflare_purge stinchcombe_llm_discovery"

case "${1:-deploy}" in
  deploy)
    "$DRUSH" cache:rebuild
    "$DRUSH" updatedb -y
    # shellcheck disable=SC2086
    "$DRUSH" pm:enable $managed_modules -y
    "$DRUSH" updatedb -y
    "$DRUSH" php:script scripts/apply-managed-config.php
    "$DRUSH" cache:rebuild
    "$DRUSH" pm:list --type=module --status=enabled --format=json
    ;;
  acceptance-targets)
    "$DRUSH" php:script scripts/export-acceptance-targets.php
    ;;
  purge-llms)
    "$DRUSH" php:script scripts/purge-llms-urls.php
    ;;
  *)
    exec "$DRUSH" "$@"
    ;;
esac
