<?php

namespace Drupal\Tests\stinchcombe_domain_config_context\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\stinchcombe_domain_config_context\Config\SparseLanguageConfigOverride;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Tests sparse language override persistence.
 */
#[CoversClass(SparseLanguageConfigOverride::class)]
#[Group('stinchcombe_domain_config_context')]
final class SparseLanguageConfigOverrideTest extends UnitTestCase {

  /**
   * Tests that only values differing from the inherited context are stored.
   */
  public function testSaveWritesOnlyDifferences(): void {
    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    \Drupal::setContainer($container);

    $storage = $this->createMock(StorageInterface::class);
    $storage->expects($this->once())
      ->method('write')
      ->with('system.site', ['name' => 'Nom français']);

    $typed_config = $this->createMock(TypedConfigManagerInterface::class);
    $typed_config->method('hasConfigSchema')->willReturn(FALSE);

    $override = new SparseLanguageConfigOverride(
      'system.site',
      $storage,
      $typed_config,
      $this->createMock(EventDispatcherInterface::class),
      ['name' => 'English name', 'slogan' => 'Shared slogan'],
      'fr',
    );
    $override->initWithData([
      'name' => 'English name',
      'slogan' => 'Shared slogan',
    ]);
    $override->set('name', 'Nom français')->save();

    self::assertSame('fr', $override->getLangcode());
    self::assertSame(['name' => 'Nom français'], $override->getRawData());
  }

}
