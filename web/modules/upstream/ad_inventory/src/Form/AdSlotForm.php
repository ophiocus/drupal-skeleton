<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdCreative;
use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\ad_inventory\Entity\AdProvider;
use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\ad_inventory\Entity\AdSlotInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits an ad slot.
 */
final class AdSlotForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    $slot = $this->entity;
    assert($slot instanceof AdSlotInterface);
    $formats = [];
    foreach (AdFormat::loadMultiple() as $id => $format) {
      $formats[$id] = sprintf('%s (%d×%d)', $format->label(), $format->width(), $format->height());
    }
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $slot->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $slot->id(),
      '#machine_name' => ['exists' => [AdSlot::class, 'load']],
      '#disabled' => !$slot->isNew(),
    ];
    $form['channel'] = [
      '#type' => 'radios',
      '#title' => $this->t('Channel'),
      '#options' => [
        'web' => $this->t("Web: a block, or a template's #type ad_slot"),
        'email' => $this->t('Email: an image and a click address for mail templates'),
      ],
      '#default_value' => $slot->channel(),
    ];
    $form['desktop_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Desktop format (the only one for email)'),
      '#options' => $formats,
      '#required' => TRUE,
      '#default_value' => $slot->desktopFormat(),
    ];
    $form['phone_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Phone format'),
      '#options' => $formats,
      '#empty_option' => $this->t('- The desktop format -'),
      '#default_value' => $slot->isNew() ? '' : $slot->phoneFormat(),
      '#states' => ['visible' => [':input[name="channel"]' => ['value' => 'web']]],
    ];
    $form['alternative_formats'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Formats per-page controls may pick instead'),
      '#options' => $formats,
      '#default_value' => $slot->alternativeFormats(),
      '#states' => ['visible' => [':input[name="channel"]' => ['value' => 'web']]],
    ];
    $form['sources'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('May be filled by'),
      '#description' => $this->t('In this order: a booking in force, then the network providers in their order, then a house creative. Email slots never take a network.'),
      '#options' => [
        'booking' => $this->t('Bookings'),
        'network' => $this->t('Network providers'),
        'house' => $this->t('House creatives'),
      ],
      '#default_value' => $slot->sources(),
    ];
    $form['booking_kinds'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Creative kinds a booking may carry here'),
      '#description' => $this->t('None checked: every kind.'),
      '#options' => AdCreative::kindOptions(),
      '#default_value' => $slot->bookingKinds(),
    ];
    $form['group'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Slot group'),
      '#description' => $this->t('Optional, lowercase letters, digits and underscores. A booking may name the group instead of each slot in it.'),
      '#default_value' => $slot->group(),
      '#maxlength' => 64,
    ];
    $form['repeatable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Repeatable: a template may print it several times on one page'),
      '#default_value' => $slot->isRepeatable(),
      '#states' => ['visible' => [':input[name="channel"]' => ['value' => 'web']]],
    ];

    $form['units'] = [
      '#type' => 'details',
      '#title' => $this->t('Network units'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('The unit each provider serves here. A provider without a unit is skipped in this slot.'),
    ];
    $units = $slot->units();
    $snippets = $this->currentUser()->hasPermission('administer pasted ad snippets');
    foreach (AdProvider::loadMultiple() as $id => $provider) {
      $plugin = $provider->plugin();
      if ($plugin === NULL) {
        continue;
      }
      $form['units'][$id] = [
        '#type' => 'details',
        '#title' => $provider->label(),
        '#open' => isset($units[$id]),
        '#access' => !$provider->isRestricted() || $snippets,
      ];
      foreach ($plugin->unitFields() as $field => $label) {
        $form['units'][$id][$field] = [
          '#type' => $field === 'markup' ? 'textarea' : 'textfield',
          '#title' => $label,
          '#default_value' => $units[$id][$field] ?? '',
        ];
      }
    }
    if (!AdProvider::loadMultiple()) {
      $form['units']['none'] = ['#markup' => $this->t('No provider is configured.')];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $group = trim((string) $form_state->getValue('group'));
    if ($group !== '' && !preg_match('/^[a-z0-9_]+$/', $group)) {
      $form_state->setErrorByName('group', $this->t('Use lowercase letters, digits and underscores.'));
    }
    if (!array_filter((array) $form_state->getValue('sources'))) {
      $form_state->setErrorByName('sources', $this->t('Choose at least one fill source.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $slot = parent::buildEntity($form, $form_state);
    assert($slot instanceof AdSlotInterface);
    $original = $this->entity->isNew() ? [] : AdSlot::load($this->entity->id())?->units() ?? [];
    $units = [];
    foreach ((array) $form_state->getValue('units') as $provider => $fields) {
      if (!is_array($fields)) {
        continue;
      }
      $fields = array_filter(array_map(static fn($v): string => trim((string) $v), $fields), static fn(string $v): bool => $v !== '');
      if ($fields) {
        $units[$provider] = $fields;
      }
    }
    // Units the editor could not see stay as they were.
    foreach ($original as $provider => $fields) {
      if (!isset($form['units'][$provider]) || (isset($form['units'][$provider]['#access']) && !$form['units'][$provider]['#access'])) {
        $units[$provider] = $fields;
      }
    }
    $slot->set('units', $units);
    $slot->set('alternative_formats', array_values(array_filter((array) $form_state->getValue('alternative_formats'))));
    $slot->set('sources', array_values(array_intersect(AdSlotInterface::SOURCES, array_filter((array) $form_state->getValue('sources')))));
    $slot->set('booking_kinds', array_values(array_filter((array) $form_state->getValue('booking_kinds'))));
    $slot->set('repeatable', (bool) $form_state->getValue('repeatable'));
    $slot->set('group', trim((string) $form_state->getValue('group')));
    $slot->set('phone_format', (string) $form_state->getValue('phone_format'));
    return $slot;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the slot %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}
