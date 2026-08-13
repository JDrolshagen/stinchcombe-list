<?php

namespace Drupal\stinchcombe_domain_config_context\Hook;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\domain_config_switcher\DomainConfigSwitcherInterface;
use Drupal\domain_config_ui\DomainConfigEditContextInterface;
use Drupal\domain_config_ui\DomainConfigUIManagerInterface;
use Drupal\stinchcombe_domain_config_context\ConfigurationContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds All Domains and language selectors to compatible config forms.
 */
final class ConfigurationContextFormHooks {

  use StringTranslationTrait;

  public function __construct(
    private ConfigurationContext $context,
    private DomainConfigEditContextInterface $editContext,
    private DomainConfigUIManagerInterface $domainConfigUiManager,
    private LanguageManagerInterface $languageManager,
    private AccountProxyInterface $currentUser,
    private RequestStack $requestStack,
  ) {}

  /**
   * Alters the contrib switcher after it has identified a compatible form.
   */
  #[Hook('form_alter', order: new OrderAfter(['domain_config_switcher']))]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (!isset($form['domain_config_switcher']) || !$form_state->getFormObject() instanceof ConfigFormBase) {
      return;
    }

    $config_names = $this->configTargetNames($form);
    if ($config_names === []) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $domain_query = $request?->query->get(DomainConfigSwitcherInterface::QUERY_ARG);
    $domain_id = $this->editContext->getDomainId(reset($config_names));

    if ($domain_query === DomainConfigEditContextInterface::BASE
      && $this->currentUser->hasPermission('set default domain configuration')) {
      $domain_id = DomainConfigEditContextInterface::BASE;
      $this->editContext->setEditingDomain($domain_id, $config_names);
      $form['domain_config_switcher']['domain']['#options'] = [
        DomainConfigEditContextInterface::BASE => $this->t('All Domains'),
      ] + $form['domain_config_switcher']['domain']['#options'];
      $form['domain_config_switcher']['domain']['#default_value'] = $domain_id;
    }
    elseif ($this->currentUser->hasPermission('set default domain configuration')) {
      $form['domain_config_switcher']['domain']['#options'] = [
        DomainConfigEditContextInterface::BASE => $this->t('All Domains'),
      ] + $form['domain_config_switcher']['domain']['#options'];
    }

    $langcode = NULL;
    $languages = $this->languageManager->getLanguages();
    $language_query = $request?->query->get(ConfigurationContext::LANGUAGE_QUERY_ARG);
    if ($this->currentUser->hasPermission('translate domain configuration')
      && is_string($language_query)
      && isset($languages[$language_query])) {
      $langcode = $language_query;
    }

    $language_options = ['' => $this->t('- Default language -')];
    foreach ($languages as $language) {
      $language_options[$language->getId()] = $language->getName();
    }
    $form['domain_config_switcher']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $language_options,
      '#default_value' => $langcode ?? '',
      '#access' => $this->currentUser->hasPermission('translate domain configuration'),
      '#weight' => 0,
    ];
    $form['domain_config_switcher']['switch']['#submit'] = [[static::class, 'switchSubmit']];
    $form['domain_config_switcher']['help']['#value'] = $this->t('Choose All Domains or an individual domain, plus an optional language, to view and edit that isolated configuration context. Switching discards unsaved changes.');

    $this->context->set($domain_id, $langcode, $config_names);
    if ($langcode !== NULL
      && $domain_id !== DomainConfigEditContextInterface::BASE
      && ($domain_id === NULL || !$this->domainConfigUiManager->isConfigurationRegisteredForDomain($domain_id, $config_names))) {
      $form_state->set('stinchcombe_domain_config_context_requires_override', TRUE);
      $form['#validate'][] = [static::class, 'validateLanguageContext'];
    }
    $form['#cache']['contexts'][] = 'url.query_args:' . DomainConfigSwitcherInterface::QUERY_ARG;
    $form['#cache']['contexts'][] = 'url.query_args:' . ConfigurationContext::LANGUAGE_QUERY_ARG;
    $form['#cache']['contexts'][] = 'user.permissions';
  }

  /**
   * Prevents a language override from being saved before its domain is enabled.
   */
  public static function validateLanguageContext(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('stinchcombe_domain_config_context_requires_override')) {
      $form_state->setErrorByName(
        'domain_config_switcher][language',
        new TranslatableMarkup('Enable domain configuration for this form before saving a language-specific value.'),
      );
    }
  }

  /**
   * Redirects back to the current form with both selected contexts.
   */
  public static function switchSubmit(array &$form, FormStateInterface $form_state): void {
    $query = \Drupal::request()->query->all();
    $domain_id = $form_state->getValue(['domain_config_switcher', 'domain'], '');
    $langcode = $form_state->getValue(['domain_config_switcher', 'language'], '');

    if ($domain_id === '') {
      unset($query[DomainConfigSwitcherInterface::QUERY_ARG]);
    }
    else {
      $query[DomainConfigSwitcherInterface::QUERY_ARG] = $domain_id;
    }
    if ($langcode === '') {
      unset($query[ConfigurationContext::LANGUAGE_QUERY_ARG]);
    }
    else {
      $query[ConfigurationContext::LANGUAGE_QUERY_ARG] = $langcode;
    }
    $form_state->setRedirect('<current>', [], ['query' => $query]);
  }

  /**
   * Finds the configuration names used by #config_target elements.
   *
   * @return string[]
   *   Unique configuration names.
   */
  private function configTargetNames(array $form): array {
    $names = [];
    $collect = function (array $element) use (&$collect, &$names): void {
      if (isset($element['#config_target'])) {
        $target = $element['#config_target'];
        $names[] = $target instanceof ConfigTarget
          ? $target->configName
          : explode(':', (string) $target, 2)[0];
      }
      foreach (Element::children($element) as $key) {
        if (is_array($element[$key])) {
          $collect($element[$key]);
        }
      }
    };
    $collect($form);
    return array_values(array_unique(array_filter($names)));
  }

}
