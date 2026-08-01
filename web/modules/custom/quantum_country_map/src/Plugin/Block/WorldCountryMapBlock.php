<?php

declare(strict_types=1);

namespace Drupal\quantum_country_map\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a clickable world jurisdiction map block.
 */
#[Block(
  id: 'quantum_world_country_map',
  admin_label: new TranslatableMarkup('Quantum World Country Map'),
  category: new TranslatableMarkup('Quantum')
)]
final class WorldCountryMapBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $links = $this->getCountryTermLinks();

    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'quantum-world-country-map',
        'class' => ['quantum-world-country-map'],
        'aria-label' => $this->t('World jurisdiction navigation map'),
      ],
      'canvas' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'quantum-world-country-map-canvas',
          'class' => ['quantum-world-country-map__canvas'],
        ],
      ],
      'noscript' => [
        '#type' => 'inline_template',
        '#template' => '<noscript><p>{{ message }}</p></noscript>',
        '#context' => [
          'message' => $this->t('Enable JavaScript to use the jurisdiction map.'),
        ],
      ],
      '#attached' => [
        'library' => [
          'quantum_country_map/world_map',
        ],
        'drupalSettings' => [
          'quantumCountryMap' => [
            'links' => $links,
            'activeRegionCodes' => array_keys($links),
          ],
        ],
      ],
      '#cache' => [
        'tags' => [
          'taxonomy_term_list:jurisdictions',
        ],
        'contexts' => [
          'url.site',
        ],
      ],
    ];
  }

  /**
   * Builds a map of ISO-2 country codes to Jurisdictions term URLs.
   */
  private function getCountryTermLinks(): array {
    $links = [];

    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_field_manager = \Drupal::service('entity_field.manager');

    $field_definitions = $entity_field_manager->getFieldDefinitions('taxonomy_term', 'jurisdictions');

    if (!isset($field_definitions['field_iso_code'])) {
      return $links;
    }

    $term_storage = $entity_type_manager->getStorage('taxonomy_term');

    $query = $term_storage->getQuery()
      ->condition('vid', 'jurisdictions')
      ->condition('field_iso_code', NULL, 'IS NOT NULL')
      ->accessCheck(TRUE);

    $tids = $query->execute();

    if (empty($tids)) {
      return $links;
    }

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $term_storage->loadMultiple($tids);

    foreach ($terms as $term) {
      if (!$term->hasField('field_iso_code') || $term->get('field_iso_code')->isEmpty()) {
        continue;
      }

      $code = strtoupper(trim((string) $term->get('field_iso_code')->value));

      if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
        continue;
      }

      $links[$code] = $term->toUrl('canonical', ['absolute' => FALSE])->toString();
    }

    ksort($links);

    return $links;
  }

}