<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\AdInventoryException;
use Drupal\ad_inventory\AdProviderManager;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdProviderInterface;
use Drupal\ad_inventory\ProviderPolicy;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits a network provider instance.
 */
final class AdProviderForm extends EntityForm {

  public function __construct(
    protected AdProviderManager $providerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('plugin.manager.ad_provider'));
  }

  /**
   * The plugin manager.
   */
  private function manager(): AdProviderManager {
    return $this->providerManager;
  }

  /**
   * Plugin options this editor may choose.
   */
  private function pluginOptions(): array {
    $snippets = $this->currentUser()->hasPermission('administer pasted ad snippets');
    $options = [];
    foreach ($this->manager()->getDefinitions() as $id => $definition) {
      if (empty($definition['restricted']) || $snippets) {
        $options[$id] = $definition['label'];
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $provider = $this->entity;
    assert($provider instanceof AdProviderInterface);
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $provider->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $provider->id(),
      '#machine_name' => ['exists' => [AdProvider::class, 'load']],
      '#disabled' => !$provider->isNew(),
      '#description' => $this->t("Account ids are read from <code>\$settings['ad_inventory_accounts']['&lt;this name&gt;']</code> or the plugin's environment variable, never from configuration."),
    ];
    $options = $this->pluginOptions();
    if ($provider->isNew()) {
      $form['plugin'] = [
        '#type' => 'radios',
        '#title' => $this->t('Network'),
        '#options' => $options,
        '#required' => TRUE,
        '#default_value' => array_key_first($options),
      ];
      $plugins = array_keys($options);
    }
    else {
      $form['plugin_name'] = [
        '#type' => 'item',
        '#title' => $this->t('Network'),
        '#markup' => $this->manager()->hasDefinition($provider->pluginId()) ? $this->manager()->getDefinition($provider->pluginId())['label'] : $provider->pluginId(),
      ];
      $plugins = [$provider->pluginId()];
    }
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $provider->isNew() ? TRUE : $provider->status(),
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Fill order'),
      '#description' => $this->t('Lower fills first, after bookings and before house creatives.'),
      '#default_value' => $provider->weight(),
    ];
    $form['settings'] = ['#tree' => TRUE];
    foreach ($plugins as $plugin_id) {
      if (!$this->manager()->hasDefinition($plugin_id)) {
        continue;
      }
      $plugin = $this->manager()->createInstance($plugin_id, [
        'provider_id' => (string) $provider->id(),
        'settings' => $provider->pluginId() === $plugin_id ? $provider->settings() : [],
      ]);
      $form['settings'][$plugin_id] = [
        '#type' => 'details',
        '#title' => $this->t('Settings'),
        '#open' => TRUE,
      ] + $plugin->settingsForm($plugin->settings());
      if ($provider->isNew()) {
        $form['settings'][$plugin_id]['#states'] = ['visible' => [':input[name="plugin"]' => ['value' => $plugin_id]]];
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $provider = parent::buildEntity($form, $form_state);
    assert($provider instanceof AdProviderInterface);
    $plugin_id = $this->entity->isNew() ? (string) $form_state->getValue('plugin') : $this->entity->pluginId();
    $provider->set('plugin', $plugin_id);
    $settings = [];
    if ($this->manager()->hasDefinition($plugin_id)) {
      $defaults = $this->manager()->createInstance($plugin_id, [])->defaultSettings();
      $submitted = (array) $form_state->getValue(['settings', $plugin_id]);
      foreach ($defaults as $key => $default) {
        $settings[$key] = self::cast($default, $submitted[$key] ?? $default);
      }
    }
    $provider->set('settings', $settings);
    $provider->set('weight', (int) $form_state->getValue('weight'));
    $provider->setStatus((bool) $form_state->getValue('status'));
    return $provider;
  }

  /**
   * A submitted value in the type of its default.
   */
  public static function cast(mixed $default, mixed $value): mixed {
    if (is_array($default)) {
      if (is_string($value)) {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
      }
      return array_values(array_filter((array) $value, static fn($v): bool => $v !== 0 && $v !== '0' && $v !== '' && $v !== NULL));
    }
    if (is_bool($default)) {
      return (bool) $value;
    }
    if (is_int($default)) {
      return (int) $value;
    }
    return trim((string) $value);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $provider = $this->buildEntity($form, $form_state);
    assert($provider instanceof AdProviderInterface);
    if ($provider->isRestricted() && !$this->currentUser()->hasPermission('administer pasted ad snippets')) {
      $form_state->setErrorByName('plugin', $this->t('This network needs the "Administer pasted ad snippets" permission.'));
      return;
    }
    $plugin = $provider->plugin();
    if ($plugin === NULL || !$provider->status()) {
      return;
    }
    $described = [];
    foreach (AdProvider::loadMultiple() as $other) {
      if ($other->id() !== $provider->id() && $other->status() && ($other_plugin = $other->plugin())) {
        $described[] = ProviderPolicy::describe((string) $other->id(), (string) $other->label(), $other_plugin);
      }
    }
    $described[] = ProviderPolicy::describe((string) $provider->id(), (string) $provider->label(), $plugin);
    foreach (ProviderPolicy::conflicts($described, (string) $provider->id()) as $conflict) {
      $form_state->setErrorByName('status', $conflict);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    try {
      $status = parent::save($form, $form_state);
    }
    catch (AdInventoryException $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRebuild();
      return 0;
    }
    $this->messenger()->addStatus($this->t('Saved the provider %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
