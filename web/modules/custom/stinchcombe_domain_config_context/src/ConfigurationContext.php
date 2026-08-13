<?php

namespace Drupal\stinchcombe_domain_config_context;

use Drupal\domain_config_ui\DomainConfigEditContextInterface;

/**
 * Holds the explicitly selected configuration context for the current form.
 */
final class ConfigurationContext {

  public const LANGUAGE_QUERY_ARG = 'domain_config_language';

  /**
   * Constructs a configuration context.
   *
   * @param string|null $domainId
   *   A domain id, DomainConfigEditContextInterface::BASE, or NULL.
   * @param string|null $langcode
   *   An enabled language code, or NULL for language-neutral configuration.
   * @param string[] $configNames
   *   Configuration names to which this context applies.
   */
  public function __construct(
    private ?string $domainId = NULL,
    private ?string $langcode = NULL,
    private array $configNames = [],
  ) {}

  /**
   * Sets the context for a compatible configuration form.
   */
  public function set(?string $domain_id, ?string $langcode, array $config_names): void {
    $this->domainId = $domain_id;
    $this->langcode = $langcode;
    $this->configNames = array_values(array_unique($config_names));
  }

  /**
   * Returns whether language-specific editing applies to a config object.
   */
  public function appliesTo(string $config_name): bool {
    return $this->langcode !== NULL && in_array($config_name, $this->configNames, TRUE);
  }

  /**
   * Returns the selected domain id or the base-config sentinel.
   */
  public function getDomainId(): ?string {
    return $this->domainId;
  }

  /**
   * Returns the selected language code.
   */
  public function getLangcode(): ?string {
    return $this->langcode;
  }

  /**
   * Returns whether the base/default collection is selected.
   */
  public function isBase(): bool {
    return $this->domainId === DomainConfigEditContextInterface::BASE;
  }

}
