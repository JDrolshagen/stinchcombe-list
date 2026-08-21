<?php

declare(strict_types=1);

$purge = \Drupal::service('cloudflare_purge.purge');
if (!$purge->hasCredentials()) {
  throw new RuntimeException('Cloudflare credentials are not configured; exact URL purge was not attempted.');
}

$urls = [];
$domain_storage = \Drupal::entityTypeManager()->getStorage('domain');
foreach ($domain_storage->loadMultiple() as $domain) {
  if (!$domain->status()) {
    continue;
  }
  $path_prefix = trim($domain->getPathPrefix(), '/');
  $path = $path_prefix === '' ? '/llms.txt' : '/' . $path_prefix . '/llms.txt';
  $urls[] = 'https://' . $domain->getCanonical() . $path;
}
sort($urls);

$result = $purge->purgeByUrls($urls, FALSE);
if (!$result->isSuccess()) {
  throw new RuntimeException('Cloudflare exact URL purge failed: ' . $result->getMessage());
}

print 'STINCHCOMBE_CLOUDFLARE_PURGE=' . json_encode([
  'requested' => count($urls),
  'purged' => $result->getPurgedCount() ?? count($urls),
  'urls' => $urls,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
