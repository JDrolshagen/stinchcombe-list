<?php

namespace Drupal\stinchcombe_domain_config_context\Hook;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\domain_config_switcher\DomainConfigSwitcherInterface;
use Drupal\domain_config\Config\DomainConfigFactoryOverrideInterface;
use Drupal\domain_config_ui\DomainConfigEditContextInterface;
use Drupal\domain_config_ui\DomainConfigUIManagerInterface;
use Drupal\domain_config_ui\Hook\DomainConfigUiFormHooks;
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
    private DomainConfigSwitcherInterface $domainConfigSwitcher,
    private DomainConfigFactoryOverrideInterface $domainOverrideFactory,
    private DomainConfigUiFormHooks $domainConfigUiFormHooks,
    private StorageInterface $configStorage,
    private LanguageManagerInterface $languageManager,
    private AccountProxyInterface $currentUser,
    private RequestStack $requestStack,
  ) {}

  /**
   * Alters the contrib switcher after it has identified a compatible form.
   */
  #[Hook('form_alter', order: new OrderAfter(['domain_config_switcher']))]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_id === 'language_admin_overview_form') {
      $this->alterLanguageOverview($form, $form_state);
      return;
    }

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
   * Adds domain-aware default-language editing to the language overview.
   */
  private function alterLanguageOverview(array &$form, FormStateInterface $form_state): void {
    $domains = $this->domainConfigSwitcher->getSelectableDomains();
    if ($domains === []) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $domain_query = $request?->query->get(DomainConfigSwitcherInterface::QUERY_ARG);
    $domain_id = NULL;
    if ($domain_query === DomainConfigEditContextInterface::BASE
      && $this->currentUser->hasPermission('set default domain configuration')) {
      $domain_id = DomainConfigEditContextInterface::BASE;
    }
    elseif (is_string($domain_query) && isset($domains[$domain_query])) {
      $domain_id = $domain_query;
    }

    $config_names = ['system.site', 'language.negotiation'];
    if ($domain_id !== NULL) {
      $this->editContext->setEditingDomain($domain_id, $config_names);
    }

    $options = ['' => $this->t('- Current domain -')];
    if ($this->currentUser->hasPermission('set default domain configuration')) {
      $options[DomainConfigEditContextInterface::BASE] = $this->t('All Domains');
    }
    foreach ($domains as $domain) {
      $options[$domain->id()] = $domain->label();
    }

    $form['domain_config_switcher'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#weight' => -100,
      '#attributes' => ['class' => ['container-inline', 'domain-config-switcher']],
      '#attached' => ['library' => ['domain_config_switcher/switcher']],
      'domain' => [
        '#type' => 'select',
        '#title' => $this->t('Configure domain'),
        '#options' => $options,
        '#default_value' => $domain_id ?? '',
      ],
      'switch' => [
        '#type' => 'submit',
        '#value' => $this->t('Switch'),
        '#submit' => [[static::class, 'switchSubmit']],
        '#limit_validation_errors' => [['domain_config_switcher', 'domain']],
        '#attributes' => ['class' => ['button--small', 'domain-config-switcher__submit']],
        '#weight' => 1,
      ],
      'help' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['domain-config-switcher__help']],
        '#value' => $this->t('Choose a domain, then select its default language below. This changes the default language for that domain, not a translated configuration value.'),
        '#weight' => 10,
      ],
    ];

    $resolved_domain_id = $domain_id === DomainConfigEditContextInterface::BASE
      ? NULL
      : $this->editContext->getDomainId('system.site');
    if ($resolved_domain_id === NULL) {
      $site_data = $this->configStorage->read('system.site') ?: [];
      $default_langcode = $site_data['default_langcode'] ?? NULL;
    }
    else {
      $default_langcode = $this->domainOverrideFactory
        ->getOverrideEditable($resolved_domain_id, 'system.site')
        ->get('default_langcode');
    }
    if (is_string($default_langcode)) {
      $this->setDefaultLanguageRadio($form, $default_langcode);
    }

    if ($domain_id !== NULL && $domain_id !== DomainConfigEditContextInterface::BASE) {
      $this->domainConfigUiFormHooks->enableDomainConfigForm($form, $config_names);
      if (!$this->domainConfigUiManager->isConfigurationRegisteredForDomain($domain_id, $config_names)) {
        $form_state->set('stinchcombe_domain_default_language_requires_override', TRUE);
        $form['#validate'][] = [static::class, 'validateDefaultLanguageContext'];
      }
    }
    $form['#submit'][] = [static::class, 'saveDomainDefaultLanguage'];
    $form['#cache']['contexts'][] = 'url.query_args:' . DomainConfigSwitcherInterface::QUERY_ARG;
    $form['#cache']['contexts'][] = 'user.permissions';
  }

  /**
   * Prevents changing the global default through a disabled domain context.
   */
  public static function validateDefaultLanguageContext(array &$form, FormStateInterface $form_state): void {
    if ($form_state->get('stinchcombe_domain_default_language_requires_override')) {
      $form_state->setErrorByName(
        'site_default_language',
        new TranslatableMarkup('Enable domain configuration before saving a default language for this domain.'),
      );
    }
  }

  /**
   * Marks the selected context's default language radio.
   */
  private function setDefaultLanguageRadio(array &$element, string $langcode): void {
    if (($element['#parents'] ?? NULL) === ['site_default_language']
      && isset($element['#return_value'])) {
      if ($element['#return_value'] === $langcode) {
        $element['#default_value'] = $langcode;
      }
      else {
        unset($element['#default_value']);
      }
    }
    foreach (Element::children($element) as $key) {
      if (is_array($element[$key])) {
        $this->setDefaultLanguageRadio($element[$key], $langcode);
      }
    }
  }

  /**
   * Persists the selected domain's default language and preserves context.
   */
  public static function saveDomainDefaultLanguage(array &$form, FormStateInterface $form_state): void {
    $langcode = $form_state->getValue('site_default_language');
    if (is_string($langcode) && $langcode !== '') {
      $config_factory = \Drupal::configFactory();
      $config_factory
        ->getEditable('system.site')
        ->set('default_langcode', $langcode)
        ->save();

      $negotiation = $config_factory->getEditable('language.negotiation');
      $prefixes = $negotiation->get('url.prefixes') ?? [];
      $negotiation_changed = FALSE;
      foreach ($prefixes as $prefix_langcode => $prefix) {
        if ($prefix_langcode !== $langcode && $prefix === '') {
          $negotiation->set('url.prefixes.' . $prefix_langcode, $prefix_langcode);
          $negotiation_changed = TRUE;
        }
      }
      if (($prefixes[$langcode] ?? NULL) !== '') {
        $negotiation->set('url.prefixes.' . $langcode, '');
        $negotiation_changed = TRUE;
      }
      if ($negotiation_changed) {
        $negotiation->save();
      }
      \Drupal::languageManager()->reset();
    }

    $query = \Drupal::request()->query->all();
    $form_state->setRedirect('<current>', [], ['query' => $query]);
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
