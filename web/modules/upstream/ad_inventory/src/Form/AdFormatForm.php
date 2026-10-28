<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Adds or edits a banner format.
 */
final class AdFormatForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\ad_inventory\Entity\AdFormat $format */
    $format = $this->entity;
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $format->label(),
      '#required' => TRUE,
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $format->id(),
      '#machine_name' => ['exists' => [static::class, 'exists']],
      '#disabled' => !$format->isNew(),
    ];
    $form['width'] = [
      '#type' => 'number',
      '#title' => $this->t('Width (CSS px)'),
      '#default_value' => $format->width() ?: NULL,
      '#min' => 50,
      '#max' => 1200,
      '#required' => TRUE,
    ];
    $form['height'] = [
      '#type' => 'number',
      '#title' => $this->t('Height (CSS px)'),
      '#default_value' => $format->height() ?: NULL,
      '#min' => 50,
      '#max' => 1200,
      '#required' => TRUE,
    ];
    $form['weight'] = [
      '#type' => 'weight',
      '#title' => $this->t('Weight'),
      '#default_value' => $format->get('weight'),
    ];
    return $form;
  }

  /**
   * Whether a format id is taken.
   */
  public static function exists(string $id): bool {
    return \Drupal::entityTypeManager()->getStorage('ad_format')->load($id) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved the format %label. Add an image style named ad_inventory_@id that scales to its width, for the 1x images.', [
      '%label' => $this->entity->label(),
      '@id' => $this->entity->id(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
