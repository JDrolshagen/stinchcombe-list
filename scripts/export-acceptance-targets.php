<?php

declare(strict_types=1);

$entity_type_manager = \Drupal::entityTypeManager();
$domain_storage = $entity_type_manager->getStorage('domain');
$default_domain = $domain_storage->loadDefaultDomain();
$domains = [];

foreach ($domain_storage->loadMultiple() as $domain) {
  if (!$domain->status()) {
    continue;
  }
  $domains[] = [
    'id' => $domain->id(),
    'name' => $domain->label(),
    'hostname' => $domain->getCanonical(),
    'path_prefix' => trim($domain->getPathPrefix(), '/'),
    'default' => $default_domain !== NULL && $domain->id() === $default_domain->id(),
  ];
}
usort($domains, static fn(array $a, array $b): int => $a['hostname'] <=> $b['hostname']);

$load_nodes = static function (?string $bundle = NULL): array {
  $query = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('status', 1)
    ->sort('changed', 'DESC')
    ->range(0, 25);
  if ($bundle !== NULL) {
    $query->condition('type', $bundle);
  }
  $targets = [];
  foreach (\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($query->execute()) as $node) {
    $targets[] = [
      'path' => '/node/' . $node->id(),
      'type' => $node->bundle(),
      'title' => $node->label(),
    ];
  }
  return $targets;
};

$term_query = \Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)
  ->sort('changed', 'DESC')
  ->range(0, 25);
$terms = [];
foreach ($entity_type_manager->getStorage('taxonomy_term')->loadMultiple($term_query->execute()) as $term) {
  $terms[] = [
    'path' => '/taxonomy/term/' . $term->id(),
    'vocabulary' => $term->bundle(),
    'name' => $term->label(),
  ];
}

$view_paths = [];
foreach ($entity_type_manager->getStorage('view')->loadMultiple() as $view) {
  if (!$view->status()) {
    continue;
  }
  foreach ($view->get('display') as $display) {
    if (($display['display_plugin'] ?? '') !== 'page') {
      continue;
    }
    $options = $display['display_options'] ?? [];
    $path = trim((string) ($options['path'] ?? ''), '/');
    if ($path === '' || str_starts_with($path, 'admin/') || str_contains($path, '%')) {
      continue;
    }
    $view_paths[] = '/' . $path;
  }
}
$view_paths = array_values(array_unique($view_paths));
sort($view_paths);

$result = [
  'domains' => $domains,
  'default_hostname' => $default_domain?->getCanonical(),
  'view_paths' => $view_paths,
  'nodes' => $load_nodes(),
  'article_nodes' => $load_nodes('article'),
  'taxonomy_terms' => $terms,
];

print 'STINCHCOMBE_ACCEPTANCE_JSON=' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
