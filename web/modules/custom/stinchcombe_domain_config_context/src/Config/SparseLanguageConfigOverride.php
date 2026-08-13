<?php

namespace Drupal\stinchcombe_domain_config_context\Config;

use Drupal\Component\Utility\DiffArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Editable language override that stores only differences from its parent.
 */
final class SparseLanguageConfigOverride extends LanguageConfigOverride {

  /**
   * Constructs a sparse language override.
   */
  public function __construct(
    string $name,
    StorageInterface $storage,
    TypedConfigManagerInterface $typed_config,
    EventDispatcherInterface $event_dispatcher,
    private array $inheritedData,
    private string $langcode,
  ) {
    parent::__construct($name, $storage, $typed_config, $event_dispatcher);
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode() {
    return $this->langcode;
  }

  /**
   * Sets whether this override is new.
   */
  public function setNew(bool $new): self {
    $this->isNew = $new;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function save($has_trusted_data = FALSE) {
    if (!$has_trusted_data && $this->typedConfigManager->hasConfigSchema($this->name)) {
      $full_data = $this->castThroughSchema($this->data);
      $inherited_data = $this->castThroughSchema($this->inheritedData);
    }
    else {
      $full_data = $this->data;
      $inherited_data = $this->inheritedData;
    }

    $difference = DiffArray::diffAssocRecursive($full_data, $inherited_data);
    if ($difference === []) {
      if ($this->storage->exists($this->name)) {
        return $this->delete();
      }
      $this->data = [];
      $this->originalData = [];
      $this->isNew = TRUE;
      return $this;
    }

    $this->data = $difference;
    // Values were cast above, or the caller explicitly trusted them.
    return parent::save(TRUE);
  }

  /**
   * Casts arbitrary data through this configuration object's schema.
   */
  private function castThroughSchema(array $data): array {
    if ($data === []) {
      return [];
    }
    $original_data = $this->data;
    $this->data = $data;
    $this->schemaWrapper = NULL;
    try {
      return $this->castValue(NULL, $data);
    }
    finally {
      $this->data = $original_data;
      $this->schemaWrapper = NULL;
    }
  }

}
