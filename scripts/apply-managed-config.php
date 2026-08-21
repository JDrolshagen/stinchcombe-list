<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$managed_directory = dirname(__DIR__) . '/config/managed/llm-discovery';
$manifest_path = $managed_directory . '/manifest.yml';

if (!is_file($manifest_path)) {
  throw new RuntimeException("Managed configuration manifest not found: {$manifest_path}");
}

$manifest = Yaml::parseFile($manifest_path);
if (!is_array($manifest) || !isset($manifest['modules'], $manifest['configuration'])) {
  throw new RuntimeException('Managed configuration manifest is malformed.');
}

$module_handler = \Drupal::moduleHandler();
foreach ($manifest['modules'] as $module) {
  if (!is_string($module) || !$module_handler->moduleExists($module)) {
    throw new RuntimeException("Required module is not enabled: {$module}");
  }
}

$allowed_names = [];
foreach ($manifest['configuration'] as $item) {
  if (!is_array($item) || !isset($item['name'], $item['file'], $item['strategy'])) {
    throw new RuntimeException('Every managed configuration item requires name, file, and strategy values.');
  }
  $allowed_names[] = $item['name'];
}

$expected_names = [
  'cloudflare_purge.settings',
  'llms_txt.settings',
  'markdownify.settings',
  'metatag.metatag_defaults.global',
  'metatag.metatag_defaults.node',
  'metatag.metatag_defaults.node__article',
  'metatag.metatag_defaults.taxonomy_term',
];
sort($allowed_names);
sort($expected_names);
if ($allowed_names !== $expected_names) {
  throw new RuntimeException('The managed configuration allowlist differs from the audited release allowlist.');
}

$config_factory = \Drupal::configFactory();
$applied = [];

foreach ($manifest['configuration'] as $item) {
  $name = (string) $item['name'];
  $strategy = (string) $item['strategy'];
  $source_path = $managed_directory . '/' . basename((string) $item['file']);
  if (!is_file($source_path)) {
    throw new RuntimeException("Managed configuration source not found: {$source_path}");
  }

  $source = Yaml::parseFile($source_path);
  if (!is_array($source)) {
    throw new RuntimeException("Managed configuration source is not a mapping: {$source_path}");
  }

  if ($name === 'cloudflare_purge.settings') {
    $forbidden_credentials = [
      'authorization',
      'authorization_key',
      'bearer_token',
      'bearer_token_key',
      'email',
      'email_key',
      'zone_id',
      'zone_id_key',
    ];
    if (array_intersect($forbidden_credentials, array_keys($source)) !== []) {
      throw new RuntimeException('Cloudflare credentials must be supplied by runtime settings, never managed YAML.');
    }
  }

  $editable = $config_factory->getEditable($name);
  $existing = $editable->getRawData();

  if ($strategy === 'replace') {
    $desired = $source;
  }
  elseif ($strategy === 'merge') {
    $desired = array_replace_recursive($existing, $source);
  }
  elseif ($strategy === 'merge_tags') {
    if (!isset($source['tags']) || !is_array($source['tags'])) {
      throw new RuntimeException("Metatag source has no tags mapping: {$source_path}");
    }
    if ($existing === [] && $name !== 'metatag.metatag_defaults.node__article') {
      throw new RuntimeException("Expected production Metatag defaults are missing: {$name}");
    }
    $desired = $existing === [] ? $source : $existing;
    $desired['tags'] = array_replace($existing['tags'] ?? [], $source['tags']);
  }
  else {
    throw new RuntimeException("Unsupported managed configuration strategy: {$strategy}");
  }

  $editable->setData($desired)->save();
  $applied[] = $name;
}

sort($applied);
print 'STINCHCOMBE_MANAGED_CONFIG=' . json_encode($applied, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
