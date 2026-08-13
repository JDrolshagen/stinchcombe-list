<?php

namespace Drupal\Tests\stinchcombe_domain_config_context\Unit;

use Drupal\domain_config_ui\DomainConfigEditContextInterface;
use Drupal\stinchcombe_domain_config_context\ConfigurationContext;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the selected domain and language context.
 */
#[CoversClass(ConfigurationContext::class)]
#[Group('stinchcombe_domain_config_context')]
final class ConfigurationContextTest extends UnitTestCase {

  /**
   * Tests name scoping and base-context detection.
   */
  public function testContextIsScopedToSelectedConfigurationNames(): void {
    $context = new ConfigurationContext();
    self::assertFalse($context->appliesTo('system.site'));

    $context->set('stinchcombe', 'fr', ['system.site']);
    self::assertTrue($context->appliesTo('system.site'));
    self::assertFalse($context->appliesTo('system.theme'));
    self::assertSame('stinchcombe', $context->getDomainId());
    self::assertSame('fr', $context->getLangcode());
    self::assertFalse($context->isBase());

    $context->set(DomainConfigEditContextInterface::BASE, 'en', ['system.site']);
    self::assertTrue($context->isBase());
  }

}
