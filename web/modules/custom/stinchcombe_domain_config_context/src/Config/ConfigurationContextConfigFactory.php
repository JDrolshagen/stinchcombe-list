<?php

namespace Drupal\stinchcombe_domain_config_context\Config;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\domain_config\Config\DomainConfigFactoryOverrideInterface;
use Drupal\domain_config_language\Config\DomainLanguageConfigFactoryOverrideInterface;
use Drupal\domain_config_ui\DomainConfigUIManagerInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\stinchcombe_domain_config_context\ConfigurationContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Routes editable config to a selected language collection when requested.
 */
final class ConfigurationContextConfigFactory implements ConfigFactoryInterface {

  public function __construct(
    private ConfigFactoryInterface $inner,
    private ConfigurationContext $context,
    private StorageInterface $configStorage,
    private EventDispatcherInterface $eventDispatcher,
    private TypedConfigManagerInterface $typedConfigManager,
    private DomainConfigFactoryOverrideInterface $domainOverrideFactory,
    private DomainLanguageConfigFactoryOverrideInterface $domainLanguageOverrideFactory,
    private LanguageConfigFactoryOverrideInterface $languageConfigFactoryOverride,
    private DomainConfigUIManagerInterface $domainConfigUiManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    return $this->inner->get($name);
  }

  /**
   * {@inheritdoc}
   */
  public function getEditable($name) {
    if (!$this->context->appliesTo($name)) {
      return $this->inner->getEditable($name);
    }

    $domain_id = $this->context->getDomainId();
    $langcode = $this->context->getLangcode();
    if ($langcode === NULL) {
      return $this->inner->getEditable($name);
    }

    if ($this->context->isBase()) {
      $inherited_data = $this->configStorage->read($name) ?: [];
      $target_storage = $this->languageConfigFactoryOverride->getStorage($langcode);
    }
    else {
      // A domain-language override is meaningful only after Domain Config UI
      // has registered this configuration for that domain.
      if ($domain_id === NULL || !$this->domainConfigUiManager->isConfigurationRegisteredForDomain($domain_id, $name)) {
        return $this->inner->getEditable($name);
      }
      $inherited_data = $this->domainOverrideFactory
        ->getOverrideEditable($domain_id, $name)
        ->getRawData();
      $target_storage = $this->domainLanguageOverrideFactory
        ->getDomainStorage($domain_id, $langcode);
    }

    $language_data = $target_storage->read($name);
    $has_language_data = is_array($language_data);
    $language_data = $has_language_data ? $language_data : [];
    $initial_data = NestedArray::mergeDeepArray([$inherited_data, $language_data], TRUE);

    $editable = new SparseLanguageConfigOverride(
      $name,
      $target_storage,
      $this->typedConfigManager,
      $this->eventDispatcher,
      $inherited_data,
      $langcode,
    );
    if ($initial_data !== []) {
      $editable->initWithData($initial_data);
    }
    $editable->setNew(!$has_language_data);
    return $editable;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $names) {
    return $this->inner->loadMultiple($names);
  }

  /**
   * {@inheritdoc}
   */
  public function reset($name = NULL) {
    $this->inner->reset($name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function rename($old_name, $new_name) {
    $this->inner->rename($old_name, $new_name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheKeys() {
    return array_merge($this->inner->getCacheKeys(), [
      'configuration_context:' . ($this->context->getDomainId() ?? 'current') . ':' . ($this->context->getLangcode() ?? 'default'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function clearStaticCache() {
    $this->inner->clearStaticCache();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function listAll($prefix = '') {
    return $this->inner->listAll($prefix);
  }

  /**
   * {@inheritdoc}
   */
  public function addOverride(ConfigFactoryOverrideInterface $config_factory_override) {
    $this->inner->addOverride($config_factory_override);
  }

}
