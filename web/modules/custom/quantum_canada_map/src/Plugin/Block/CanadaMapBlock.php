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
          'taxonomy_term_list:jurisdiction',
          'config:field.field.taxonomy_term.jurisdiction.field_sub_domain_link',
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
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', 'jurisdiction');
    $link_field_name = $this->getSubDomainLinkFieldName($field_definitions);

    if ($link_field_name === NULL) {
      return [
        'links' => $links,
        'activeRegionCodes' => $active_region_codes,
      ];
    }

    $term_ids = $storage->getQuery()
      ->condition('vid', 'jurisdiction')
      ->exists($link_field_name)
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
      if (!$term instanceof TermInterface || !$term->hasField($link_field_name) || $term->get($link_field_name)->isEmpty()) {
        continue;
      }

      try {
        $link_item = $term->get($link_field_name)->first();
        $field_type = $field_definitions[$link_field_name]->getType();

        if ($field_type === 'entity_reference') {
          $domain = $link_item?->entity;
          $hostname = $domain && method_exists($domain, 'getHostname')
            ? trim((string) $domain->getHostname())
            : '';
          $domain_id = trim((string) ($link_item?->target_id ?? ''));
          $region_code = $this->getRegionCode($term, $domain_id, $hostname);
          $url = $hostname !== '' ? 'https://' . $hostname : '';
        }
        else {
          $region_code = $this->getRegionCode($term);
          $url = $link_item?->getUrl()->toString() ?? '';
        }
      }
      catch (\Throwable) {
        continue;
      }

      if (!isset(self::VALID_CANADA_REGION_CODES[$region_code]) || $url === '') {
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
   * Gets the Canada region code from an ISO field or referenced domain.
   */
  private function getRegionCode(TermInterface $term, string $domain_id = '', string $hostname = ''): string {
    if ($term->hasField('field_iso_code') && !$term->get('field_iso_code')->isEmpty()) {
      return strtoupper(trim((string) $term->get('field_iso_code')->value));
    }

    foreach ([$domain_id, $hostname] as $domain_value) {
      if (preg_match('/^([a-z]{2})(?:[_.-]|$)/i', $domain_value, $matches) === 1) {
        return 'CA-' . strtoupper($matches[1]);
      }
    }

    return '';
  }

  /**
   * Finds the Jurisdiction field labelled "Sub-Domain Link".
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
    $supported_types = ['entity_reference', 'link'];

    if (isset($field_definitions['field_sub_domain_link']) && in_array($field_definitions['field_sub_domain_link']->getType(), $supported_types, TRUE)) {
      return 'field_sub_domain_link';
    }

    foreach ($field_definitions as $field_name => $field_definition) {
      $normalized_label = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $field_definition->getLabel()));

      if (in_array($field_definition->getType(), $supported_types, TRUE) && $normalized_label === 'subdomainlink') {
        return $field_name;
      }
    }

    return NULL;
  }

}
