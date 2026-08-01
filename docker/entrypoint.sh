#!/bin/sh
set -eu

mkdir -p /var/www/html/web/sites/default/files

if [ -z "${K_SERVICE:-}" ]; then
  chown -R www-data:www-data /var/www/html/web/sites/default/files
fi

exec "$@"

