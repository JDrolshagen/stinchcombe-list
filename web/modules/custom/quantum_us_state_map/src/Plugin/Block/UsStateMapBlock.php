<?php

declare(strict_types=1);

namespace Drupal\quantum_us_state_map\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Quantum US State Map block.
 *
 * @Block(
 *   id = "quantum_us_state_map",
 *   admin_label = @Translation("Quantum US State Map"),
 *   category = @Translation("Quantum")
 * )
 */
final class UsStateMapBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Valid full U.S. subdivision region codes accepted by the map asset.
   */
  private const VALID_US_REGION_CODES = [
    'US-AL', 'US-AK', 'US-AZ', 'US-AR', 'US-CA', 'US-CO', 'US-CT', 'US-DE', 'US-DC', 'US-FL',
    'US-GA', 'US-HI', 'US-ID', 'US-IL', 'US-IN', 'US-IA', 'US-KS', 'US-KY', 'US-LA', 'US-ME',
    'US-MD', 'US-MA', 'US-MI', 'US-MN', 'US-MS', 'US-MO', 'US-MT', 'US-NE', 'US-NV', 'US-NH',
    'US-NJ', 'US-NM', 'US-NY', 'US-NC', 'US-ND', 'US-OH', 'US-OK', 'US-OR', 'US-PA', 'US-RI',
    'US-SC', 'US-SD', 'US-TN', 'US-TX', 'US-UT', 'US-VT', 'US-VA', 'US-WA', 'US-WV', 'US-WI',
    'US-WY',
  ];

  /**
   * Constructs a US state map block.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
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
    $map_id = 'quantum-us-state-map-canvas-' . substr(hash('sha256', $this->getPluginId() . ':' . serialize($this->configuration)), 0, 12);
    $map_data = $this->getMapData();

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'quantum-us-state-map-block',
        ],
      ],
      'map' => [
        '#type' => 'container',
        '#attributes' => [
          'id' => $map_id,
          'class' => [
            'quantum-us-state-map-canvas',
          ],
          'data-map-id' => $map_id,
          'aria-label' => $this->t('United States jurisdiction map'),
        ],
        'fallback' => [
          '#markup' => '<div class="quantum-us-state-map__fallback">Loading United States jurisdiction map...</div>',
        ],
      ],
      '#attached' => [
        'library' => [
          'quantum_us_state_map/us_state_map',
        ],
        'drupalSettings' => [
          'quantumUsStateMap' => [
            $map_id => [
              'links' => $map_data['links'],
              'activeRegionCodes' => $map_data['activeRegionCodes'],
            ],
          ],
        ],
      ],
      '#cache' => [
        'tags' => [
          'taxonomy_term_list',
          'config:field.storage.taxonomy_term.field_iso_code',
          'config:field.field.taxonomy_term.jurisdictions.field_iso_code',
        ],
        'contexts' => [
          'url.site',
          'url.path',
          'route',
          'languages:language_interface',
        ],
      ],
    ];
  }

  /**
   * Builds map links from Jurisdictions taxonomy terms.
   *
   * @return array{links: array<string, string>, activeRegionCodes: array<int, string>}
   *   Links keyed by jsVectorMap region code, plus the active region list.
   */
  private function getMapData(): array {
    $links = [];

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'jurisdictions');

    if (!isset($field_definitions['field_iso_code'])) {
      return [
        'links' => [],
        'activeRegionCodes' => [],
      ];
    }

    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $tids = $term_storage
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'jurisdictions')
      ->execute();

    if (empty($tids)) {
      return [
        'links' => [],
        'activeRegionCodes' => [],
      ];
    }

    $terms = $term_storage->loadMultiple($tids);

    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      if (!$term->hasField('field_iso_code') || $term->get('field_iso_code')->isEmpty()) {
        continue;
      }

      $region_code = strtoupper(trim((string) $term->get('field_iso_code')->value));

      if (!preg_match('/^US-[A-Z]{2}$/', $region_code)) {
        continue;
      }

      if (!in_array($region_code, self::VALID_US_REGION_CODES, TRUE)) {
        continue;
      }

      if (isset($links[$region_code])) {
        continue;
      }

      try {
        $links[$region_code] = $term->toUrl('canonical')->toString();
      }
      catch (\Throwable) {
        continue;
      }
    }

    ksort($links);

    return [
      'links' => $links,
      'activeRegionCodes' => array_keys($links),
    ];
  }

}