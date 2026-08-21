<?php

/**
 * Google Cloud Run settings.
 *
 * Secrets are injected by Cloud Run from Secret Manager. Public files are
 * mounted from Cloud Storage at sites/default/files.
 */

$databases['default']['default'] = [
  'database' => getenv('DRUPAL_DB_NAME') ?: 'drupal',
  'username' => getenv('DRUPAL_DB_USER') ?: 'drupal',
  'password' => getenv('DRUPAL_DB_PASSWORD') ?: '',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
  'unix_socket' => getenv('DRUPAL_DB_SOCKET') ?: '',
];

$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT') ?: '';
$settings['file_temp_path'] = '/tmp';

// Generated PHP, including compiled Twig templates, must remain local to each
// Cloud Run instance. The public files directory is a shared GCS FUSE mount;
// concurrent cache rebuilds otherwise race while deleting the same objects.
$settings['php_storage']['default']['directory'] = '/tmp/drupal-php';

// Keep the SendGrid credential out of Drupal's configuration database. Cloud
// Run injects this value directly from Google Secret Manager.
$sendgrid_api_key = getenv('SENDGRID_API_KEY');
if ($sendgrid_api_key !== FALSE && $sendgrid_api_key !== '') {
  $config['sendgrid_integration.settings']['apikey'] = $sendgrid_api_key;
}

// Cloudflare credentials are runtime-only. If either secret is unavailable,
// keep automatic purging disabled so Drupal never queues requests that cannot
// be authenticated. The managed source configuration becomes active
// automatically once both Secret Manager bindings are present.
$cloudflare_zone_id = getenv('CLOUDFLARE_ZONE_ID');
$cloudflare_api_token = getenv('CLOUDFLARE_API_TOKEN');
if ($cloudflare_zone_id !== FALSE && $cloudflare_zone_id !== '' && $cloudflare_api_token !== FALSE && $cloudflare_api_token !== '') {
  $settings['cloudflare_purge_credentials'] = [
    'zone_id' => $cloudflare_zone_id,
    'bearer_token' => $cloudflare_api_token,
  ];
}
else {
  $config['cloudflare_purge.settings']['auto_purge_enabled'] = FALSE;
}

// Cloud Run terminates TLS before forwarding requests to Apache.
$settings['reverse_proxy'] = TRUE;
if (!empty($_SERVER['REMOTE_ADDR'])) {
  $settings['reverse_proxy_addresses'] = [$_SERVER['REMOTE_ADDR']];
}
$settings['reverse_proxy_trusted_headers'] =
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT |
  \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

$trusted_hosts_pattern = getenv('DRUPAL_TRUSTED_HOSTS_PATTERN');
if ($trusted_hosts_pattern) {
  $settings['trusted_host_patterns'] = [$trusted_hosts_pattern];
}

