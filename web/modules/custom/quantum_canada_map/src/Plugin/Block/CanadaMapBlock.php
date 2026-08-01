<?php

declare(strict_types=1);

namespace Drupal\quantum_canada_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Canada jurisdiction map block.
 *
 * @Block(
 *   id = "quantum_canada_map",
 *   admin_label = @Translation("Quantum Canada Map"),
 *   category = @Translation("Quantum")
 * )
 */
final class CanadaMapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  private const VALID_CANADA_REGION_CODES = [
    'CA-AB' => TRUE,
    'CA-BC' => TRUE,
    'CA-MB' => TRUE,
    'CA-NB' => TRUE,
    'CA-NL' => TRUE,
    'CA-NS' => TRUE,
    'CA-NT' => TRUE,
    'CA-NU' => TRUE,
    'CA-ON' => TRUE,
    'CA-PE' => TRUE,
    'CA-QC' => TRUE,
    'CA-SK' => TRUE,
    'CA-YT' => TRUE,
  ];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $map_id = 'quantum-canada-map-canvas-' . substr(hash('sha256', $this->getPluginId() . microtime(TRUE)), 0, 12);
    $map_data = $this->getMapData();

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['quantum-canada-map-block'],
      ],
      'canvas' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $map_id,
          'class' => ['quantum-canada-map-canvas'],
          'data-map-id' => $map_id,
          'aria-label' => $this->t('Canada jurisdiction map'),
        ],
        'fallback' => [
          '#markup' => '<div class="quantum-canada-map__fallback">Loading Canada jurisdiction map...</div>',
        ],
      ],
      '#attached' => [
        'library' => [
          'quantum_canada_map/canada_map',
        ],
        'drupalSettings' => [
          'quantumCanadaMap' => [
            $map_id => $map_data,
          ],
        ],
      ],
      '#cache' => [
        'contexts' => [
          'url.site',
          'url.path',
          'languages:language_interface',
        ],
        'tags' => [
          'taxonomy_term_list:jurisdictions',
        ],
      ],
    ];
  }

  /**
   * Builds links keyed by Canada province and territory region code.
   *
   * @return array{links: array<string, string>, activeRegionCodes: string[]}
   *   Map links and the region codes that have a link.
   */
  private function getMapData(): array {
    $links = [];
    $active_region_codes = [];

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'jurisdictions');
    $link_field_name = $this->getSubDomainLinkFieldName($field_definitions);

    if (!isset($field_definitions['field_iso_code']) || $link_field_name === NULL) {
      return [
        'links' => $links,
        'activeRegionCodes' => $active_region_codes,
      ];
    }

    $term_ids = $storage->getQuery()
      ->condition('vid', 'jurisdictions')
      ->exists('field_iso_code')
      ->accessCheck(TRUE)
      ->execute();

    if (!$term_ids) {
      return [
        'links' => $links,
        'activeRegionCodes' => $active_region_codes,
      ];
    }

    /** @var \Drupal\taxonomy\TermInterface[] $terms */
    $terms = $storage->loadMultiple($term_ids);

    foreach ($terms as $term) {
      if (!$term instanceof TermInterface || !$term->hasField('field_iso_code') || $term->get('field_iso_code')->isEmpty()) {
        continue;
      }

      $region_code = strtoupper(trim((string) $term->get('field_iso_code')->value));

      if (!isset(self::VALID_CANADA_REGION_CODES[$region_code])) {
        continue;
      }

      if (!$term->hasField($link_field_name) || $term->get($link_field_name)->isEmpty()) {
        continue;
      }

      try {
        $link_item = $term->get($link_field_name)->first();
        $url = $link_item?->getUrl()->toString();
      }
      catch (\Throwable) {
        continue;
      }

      if (!$url) {
        continue;
      }

      $links[$region_code] = $url;
      $active_region_codes[] = $region_code;
    }

    sort($active_region_codes);

    return [
      'links' => $links,
      'activeRegionCodes' => $active_region_codes,
    ];
  }

  /**
   * Finds the Jurisdictions field labelled "Sub-Domain Link".
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $field_definitions
   *   The Jurisdictions bundle's field definitions.
   *
   * @return string|null
   *   The link field's machine name, or NULL when it is not configured.
   */
  private function getSubDomainLinkFieldName(array $field_definitions): ?string {
    // Prefer the conventional machine name while supporting an existing field
    // created with a different machine name.
    if (isset($field_definitions['field_sub_domain_link']) && $field_definitions['field_sub_domain_link']->getType() === 'link') {
      return 'field_sub_domain_link';
    }

    foreach ($field_definitions as $field_name => $field_definition) {
      $normalized_label = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $field_definition->getLabel()));

      if ($field_definition->getType() === 'link' && $normalized_label === 'subdomainlink') {
        return $field_name;
      }
    }

    return NULL;
  }

}
