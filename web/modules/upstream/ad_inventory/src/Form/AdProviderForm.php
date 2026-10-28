<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Plugin\AdProviderPluginManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds or edits an ad provider.
 *
 * Refuses a provider whose formats another enabled provider forbids on the
 * same site (a pop-under beside Google, for one), and a provider type whose
 * permission the editor lacks.
 */
final class AdProviderForm extends EntityForm {

  /**
   * Provider plugins.
   */
  protected AdProviderPluginManager $providers;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->providers = $container->get('plugin.manager.ad_inventory.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ad_inventory\Entity\AdProvider $provider */
    $provider = $this->entity;
    $types = [];
    foreach ($this->providers->getDefinitions() as $id => $definition) {
      if (empty($definition['permission']) || $this->currentUser()->hasPermission($definition['permission'])) {
        $types[$id] = $definition['label'];
      }
    }
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $provider->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $provider->id(),
      '#machine_name' => ['exists' => [static::class, 'exists']],
      '#disabled' => !$provider->isNew(),
    ];
    $form['plugin'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $types,
      '#default_value' => $provider->pluginId(),
      '#required' => TRUE,
      '#disabled' => !$provider->isNew(),
      '#ajax' => ['callback' => '::settingsAjax', 'wrapper' => 'ad-provider-settings'],
    ];
    $form['state'] = [
      '#type' => 'radios',
      '#title' => $this->t('State'),
      '#options' => [
        AdProvider::DRAFT => $this->t('Draft: fills spaces only for staff who preview drafts'),
        AdProvider::APPROVED => $this->t('Approved: fills spaces for readers who granted advertising consent'),
      ],
      '#default_value' => $provider->state(),
    ];
    $slots = [];
    foreach ($this->entityTypeManager->getStorage('ad_slot')->loadMultiple() as $id => $slot) {
      $slots[$id] = $slot->label();
    }
    $form['slots'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Spaces'),
      '#description' => $this->t('None checked: every space that accepts providers.'),
      '#options' => $slots,
      '#default_value' => (array) $provider->get('slots'),
    ];
    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Order'),
      '#description' => $this->t('The first provider with a unit for a space fills it.'),
      '#default_value' => $provider->getWeight(),
    ];
    $form['settings'] = ['#type' => 'container', '#tree' => TRUE, '#attributes' => ['id' => 'ad-provider-settings']];
    $plugin_id = (string) ($form_state->getValue('plugin') ?: $provider->pluginId());
    if ($plugin_id !== '' && isset($types[$plugin_id])) {
      $plugin = $this->providers->createInstance($plugin_id, $provider->settings());
      $form['settings'] += $plugin->buildConfigurationForm([], SubformState::createForSubform($form['settings'], $form, $form_state));
    }
    return $form;
  }

  /**
   * Rebuilds the settings for the chosen type.
   *
   * @param array<string, mixed> $form
   *   The form.
   *
   * @return array<string, mixed>
   *   The settings element.
   */
  public function settingsAjax(array $form): array {
    return $form['settings'];
  }

  /**
   * Whether a provider id is taken.
   */
  public static function exists(string $id): bool {
    return \Drupal::entityTypeManager()->getStorage('ad_provider')->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $plugin_id = (string) $form_state->getValue('plugin');
    $definition = $this->providers->getDefinition($plugin_id, FALSE);
    if ($definition === NULL) {
      $form_state->setErrorByName('plugin', $this->t('Choose a type.'));
      return;
    }
    if (!empty($definition['permission']) && !$this->currentUser()->hasPermission($definition['permission'])) {
      $form_state->setErrorByName('plugin', $this->t('You may not configure this type.'));
    }
    foreach ($this->entityTypeManager->getStorage('ad_provider')->loadByProperties(['status' => TRUE]) as $id => $other) {
      if ($id === $this->entity->id() || !$other instanceof AdProvider) {
        continue;
      }
      $conflict = $this->providers->conflict($plugin_id, $other->pluginId());
      if ($conflict !== NULL) {
        $form_state->setErrorByName('plugin', $this->t('Cannot run beside %other: @why', [
          '%other' => $other->label(),
          '@why' => $conflict,
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('slots', array_values(array_filter((array) $form_state->getValue('slots'))));
    $plugin_id = (string) $form_state->getValue('plugin');
    $settings = (array) $form_state->getValue('settings');
    if ($this->providers->hasDefinition($plugin_id)) {
      /** @var \Drupal\ad_inventory\Plugin\AdProviderInterface $plugin */
      $plugin = $this->providers->createInstance($plugin_id);
      $settings = $plugin->settingsFromForm($settings);
    }
    $form_state->setValue('settings', $settings);
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the provider %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
