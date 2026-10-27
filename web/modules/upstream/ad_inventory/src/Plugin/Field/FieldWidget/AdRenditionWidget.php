<?php

declare(strict_types=1);

namespace Drupal\ad_inventory\Plugin\Field\FieldWidget;

use Drupal\ad_inventory\Entity\AdFormat;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\EntityReferenceAutocompleteWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A format select beside the media autocomplete.
 */
#[FieldWidget(
  id: 'ad_rendition_default',
  label: new TranslatableMarkup('Format and media'),
  field_types: ['ad_rendition'],
)]
class AdRenditionWidget extends EntityReferenceAutocompleteWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $widget = parent::formElement($items, $delta, $element, $form, $form_state);
    $options = [];
    foreach (AdFormat::loadMultiple() as $format) {
      $options[$format->id()] = sprintf('%s (%d×%d, upload %d×%d)', $format->label(), $format->width(), $format->height(), $format->width() * 2, $format->height() * 2);
    }
    $widget['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Format'),
      '#options' => $options,
      '#empty_option' => $this->t('- Format -'),
      '#default_value' => $items[$delta]->format ?? NULL,
      '#weight' => -1,
    ];
    $widget['target_id']['#title'] = $this->t('Media image');
    $widget['target_id']['#title_display'] = 'before';
    $widget['#type'] = 'container';
    $widget['#attributes']['class'][] = 'container-inline';
    return $widget;
  }

}
