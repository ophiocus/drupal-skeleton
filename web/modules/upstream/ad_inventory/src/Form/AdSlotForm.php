<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\ad_inventory\Entity\AdSlot;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a banner space.
 */
final class AdSlotForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ad_inventory\Entity\AdSlot $slot */
    $slot = $this->entity;
    $formats = [];
    foreach ($this->entityTypeManager->getStorage('ad_format')->loadMultiple() as $id => $format) {
      /** @var \Drupal\ad_inventory\Entity\AdFormat $format */
      $formats[$id] = $format->label() . ' ' . $format->size();
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
      '#machine_name' => ['exists' => [static::class, 'exists']],
      '#disabled' => !$slot->isNew(),
      '#description' => $this->t('Counts and bookings are kept against this name; it cannot change later.'),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Where it sits'),
      '#default_value' => $slot->description(),
      '#rows' => 2,
    ];
    $form['channel'] = [
      '#type' => 'radios',
      '#title' => $this->t('Channel'),
      '#options' => [
        AdSlot::WEB => $this->t('Web pages'),
        AdSlot::EMAIL => $this->t('Mail templates (an image and a link served by this site)'),
      ],
      '#default_value' => $slot->channel(),
    ];
    $form['desktop_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Format on wide screens'),
      '#options' => $formats,
      '#default_value' => $slot->desktopFormat(),
      '#required' => TRUE,
    ];
    $form['phone_format'] = [
      '#type' => 'select',
      '#title' => $this->t('Format on phones'),
      '#options' => $formats,
      '#default_value' => $slot->phoneFormat(),
      '#required' => TRUE,
      '#description' => $this->t('Every booking of this space carries a rendition of both formats.'),
    ];
    $form['alternatives'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Formats staff may choose on one page'),
      '#options' => $formats,
      '#default_value' => $slot->alternatives(),
    ];
    $form['fill'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('May be filled by'),
      '#options' => [
        'booking' => $this->t('Bookings'),
        'provider' => $this->t('Ad providers'),
        'house' => $this->t('House banners'),
      ],
      '#default_value' => (array) $slot->get('fill'),
    ];
    $form['kinds'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Creative kinds allowed'),
      '#description' => $this->t('Comma-separated (for example "plain, show"); empty allows any.'),
      '#default_value' => implode(', ', (array) $slot->get('kinds')),
    ];
    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight'),
      '#default_value' => $slot->get('weight'),
    ];
    return $form;
  }

  /**
   * Whether a space id is taken.
   */
  public static function exists(string $id): bool {
    return \Drupal::entityTypeManager()->getStorage('ad_slot')->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('alternatives', array_values(array_filter((array) $form_state->getValue('alternatives'))));
    $form_state->setValue('fill', array_values(array_filter((array) $form_state->getValue('fill'))));
    $kinds = array_filter(array_map('trim', explode(',', (string) $form_state->getValue('kinds'))));
    $form_state->setValue('kinds', array_values($kinds));
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the space %label. Place its block (or print it from a template) where it belongs.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
